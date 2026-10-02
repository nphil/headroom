(() => {
  'use strict';
  for (const root of document.querySelectorAll('[data-headroom]:not([data-ready])')) {
    root.dataset.ready = 'true';
    const tile = root.dataset.headroom === 'tile', q = s => root.querySelector(s);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const valid = n => n !== null && n !== undefined && Number.isFinite(Number(n));
    const number = n => valid(n) ? (Number(n) / 1073741824).toFixed(1) : '—';
    const gib = n => valid(n) ? number(n) + ' GiB' : 'Unavailable';
    const labels = {never:'Never stop',last:'Stop last',normal:'Normal',early:'Stop early',first:'Stop first'};
    const scores = {never:-1000,last:-500,normal:0,early:500,first:1000};
    const score = item => item.policy.score ?? scores[item.policy.level];
    const colors = ['var(--hr-accent)','var(--ui-color-info-500,var(--hr-accent))','var(--ui-color-success-600,var(--hr-accent))','var(--ui-color-warning-600,var(--hr-accent))','var(--ui-color-secondary-400,var(--hr-accent))','var(--ui-color-error-400,var(--hr-accent))','var(--hr-text)','var(--hr-muted)'];
    let config = null, latest = null, samples = [], timer = null, historyTime = 0, actionError = '';
    let pendingInventory=null;
    let scope = 'docker', page = 0, inFlight = false, tileVisible = true, visibleIds = '';
    const rows = new Map(), pageSize = 12;
    async function request(action, body) {
      const opts = {credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.csrf},signal:AbortSignal.timeout(body === undefined ? 15000 : 180000)};
      if (body !== undefined) { opts.method='POST'; opts.headers['Content-Type']='application/x-www-form-urlencoded'; opts.body=new URLSearchParams({csrf_token:root.dataset.csrf,payload:JSON.stringify(body)}); }
      const response = await fetch(`/plugins/headroom/api.php?action=${action}`, opts);
      let data; try { data=await response.json(); } catch { throw Error('Unraid did not return a Headroom response. Reload to check your login.'); }
      if (!response.ok || !data.ok) throw Error(data.error || 'The request could not be completed.');
      return data;
    }
    function error(text) { q('[data-error]').textContent=text; q('[data-error]').hidden=!text; }
    function feedback(text) { const el=q('[data-feedback]'); if(el) el.textContent=text; }
    async function change(action, data, button) {
      if(button) button.disabled=true;
      actionError=''; feedback('Applying… No restart needed.');
      try { const result = await request(action, data); if (result.inventory) { pendingInventory=result.inventory; if(latest)latest.items=result.inventory.items; } feedback(result.message); error(''); await loadConfig(); await status(); await activity(); }
      catch(e) { actionError=e.message; error(actionError); feedback('Not completed. Check the message and current readings before retrying.'); q('[data-error]').scrollIntoView({block:'nearest'}); }
      finally { if(button?.isConnected) button.disabled=false; }
    }
    function ring(percent, center, caption, tone = '') {
      const value=Math.max(0,Math.min(100,percent || 0));
      return `<div class="hr-ring${tone==='pending'?' pending':''}" data-tone="${tone}" aria-hidden="true"><svg viewBox="0 0 100 100"><circle class="track" cx="50" cy="50" r="43"/>${tone==='pending'?'':`<circle class="value" cx="50" cy="50" r="43" pathLength="100" stroke-dasharray="${value.toFixed(1)} 100"/>`}</svg><span class="hr-ring-center">${esc(center)}<small>${esc(caption)}</small></span></div>`;
    }
    function instrument(title, icon, graphic, main, footer) {
      return `<div class="hr-gauge-card"><div class="hr-gauge-title"><span>${title}</span><i class="fa ${icon}" aria-hidden="true"></i></div><div class="hr-gauge-body">${graphic}<div class="hr-gauge-main">${main}</div></div><div class="hr-gauge-footer">${footer}</div></div>`;
    }
    function bar(percent) { return `<div class="hr-bar" aria-hidden="true"><span style="width:${Math.max(0,Math.min(100,percent||0))}%"></span></div>`; }
    function paintStatus(data) {
      if(pendingInventory) {if((data.items_updated||0)<pendingInventory.updated)data.items=pendingInventory.items;else pendingInventory=null;}
      latest=data; const s=data.sample, z=s.zram || {};
      const available=s.total ? s.available/s.total*100 : 0;
      const saving=z.data ? Math.max(0,z.saved/z.data*100) : 0;
      const ratio=z.compressed ? (z.data/z.compressed).toFixed(1)+'×' : '—';
      const pending=!valid(s.psi_some), waiting=pending?'After next reboot':s.psi_some.toFixed(1)+'%';
      const badge=q('[data-health]'); badge.textContent=data.stale?'Readings out of date':data.health; badge.dataset.warning=String(data.stale || data.health!=='Room to spare');
      q('[data-updated]').textContent=`Updated ${new Date(data.updated*1000).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})} · ${data.watchers.includes('docker')&&data.watchers.includes('kernel')?'Automatic protection active':'Check monitoring connection'}`;
      const warnings=Object.values(data.errors||{}); if(data.stale) warnings.unshift('The monitor has not supplied a fresh reading for over 45 seconds.');
      error([actionError,...warnings].filter(Boolean).join(' '));
      if(tile) {
        q('[data-pressure]').textContent=pending?'Pressure: after reboot':`Waiting for RAM: ${waiting}`;
        q('[data-stats]').innerHTML=`<div class="hr-tile-stat"><span>Available RAM</span><strong>${number(s.available)} <small>GiB</small></strong><small>of ${number(s.total)} GiB · ${Math.round(available)}% free to use</small>${bar(available)}</div><div class="hr-tile-stat"><span>zram compression</span><strong>${ratio}</strong><small>${number(s.zram_ram)} GiB RAM stores ${number(z.data)} GiB</small>${bar(s.swap_size?s.swap_used/s.swap_size*100:0)}</div>`;
        const apps=Object.values(data.items).filter(i=>i.kind==='docker'&&i.state==='running').sort((a,b)=>b.usage-a.usage).slice(0,3), max=apps[0]?.usage||1;
        q('[data-top-apps]').innerHTML='<div class="hr-top-label">Largest apps</div>'+apps.map(i=>`<div class="hr-top-app"><span>${esc(i.name)}</span>${bar(i.usage/max*100)}<span>${gib(i.usage)}</span></div>`).join('');
        return;
      }
      q('[data-stats]').innerHTML=instrument('Available RAM','fa-microchip',ring(available,Math.round(available)+'%','available'),`<strong class="hr-number">${number(s.available)} <small>GiB</small></strong><span>ready for your apps</span><small>${number(s.ram_used)} GiB currently in use</small>`,`<span>${number(s.total)} GiB physical RAM</span><span>Includes reclaimable cache</span>`)
        +instrument('Compressed memory','fa-compress',ring(saving,Math.round(saving)+'%','RAM saved','save'),`<strong class="hr-number">${ratio} <small>compression</small></strong><span>${number(z.saved)} GiB RAM saved</span><small>${number(s.zram_ram)} GiB actual RAM cost</small>`,`<span>${number(s.swap_used)} / ${number(s.swap_size)} GiB swap used</span><span>${esc(z.algorithm||'Not active')}</span>`)
        +instrument('Memory pressure','fa-tachometer',ring(pending?0:s.psi_some,pending?'—':s.psi_some.toFixed(1)+'%',pending?'not measured':'waiting',pending?'pending':''),`<strong class="hr-number hr-pressure-title">${pending?(data.psi_requested?'After next reboot':'Statistics off'):waiting}</strong><span>${pending?'RAM & swap alerts are active':'Time tasks wait for memory'}</span><small>${pending?'No reboot will be started':`All tasks stalled: ${s.psi_full.toFixed(1)}%`}</small>`,`<span>ZFS cache ${number(s.arc_size)} / ${number(s.arc.max)} GiB</span><span>${valid(s.arc.hit_percent)?s.arc.hit_percent.toFixed(0)+'% cache hits':''}</span>`);
      const all=Object.values(data.items), early=all.filter(i=>i.kind==='docker'&&score(i)>0).map(i=>i.name), last=all.filter(i=>i.kind==='docker'&&score(i)<0).map(i=>i.name);
      const names=list=>list.length?list.slice(0,4).join(', ')+(list.length>4?` +${list.length-4} more`:''):'Normal-priority apps';
      q('[data-plan]').innerHTML=`<div class="hr-plan-line"><span>Gives way first</span><b>${esc(names(early))}</b></div><div class="hr-plan-line"><span>Keep running</span><b>${esc(names(last))}</b></div><div class="hr-plan-line"><span>Always protected</span><b>Core Unraid services</b></div>`;
      q('[data-explain]').textContent=data.explain;
      q('[data-psi-note]').textContent=pending?(data.psi_requested?'Activates after the next reboot. RAM, swap and memory-kill alerts already work.':'Pressure statistics are off. RAM, swap and memory-kill alerts remain available.'):(data.psi_requested?'Active: measuring memory delays now.':'Active now; switches off after the next reboot.');
      q('[data-zram-live]').textContent=z.device?`${z.device} · ${gib(z.size)} configured · ${gib(z.saved)} RAM saved`:'No Headroom-owned compressed swap device.';
      q('[data-arc-live]').textContent=`Now ${gib(s.arc.size)} · target ${gib(s.arc.target)} · maximum ${gib(s.arc.max)} · cache hit rate ${valid(s.arc.hit_percent)?s.arc.hit_percent.toFixed(1)+'%':'unavailable'}.`;
      q('[data-ramdisks]').innerHTML=Object.entries(s.ramdisks).map(([path,v])=>`<p><b>${esc(path)}</b>: ${gib(v.used)} allocated; directory files ${s.footprints?.bytes?.[path]==null?'not yet measured':gib(s.footprints.bytes[path])}.</p>`).join('');
      if(config) paintItems();
    }
    function options(item) {
      const p=item.policy;
      return (p.custom?`<option value="custom" selected disabled>Custom (${score(item)})</option>`:'')+Object.entries(labels).map(([value,label])=>`<option value="${value}"${!p.custom&&value===p.level?' selected':''}>${label}</option>`).join('');
    }
    function makeRow(item) {
      const p=item.policy, docker=item.kind==='docker', editable=!docker||item.template_available;
      const row=document.createElement('div'); row.className='hr-app'; row.dataset.id=item.id;
      row.innerHTML=`<div class="hr-app-main"><div class="hr-app-name"><span class="hr-app-icon" aria-hidden="true"><i class="fa ${docker?'fa-cube':item.kind==='vm'?'fa-desktop':'fa-shield'}"></i></span><div><strong>${esc(item.name)}</strong><small><i class="hr-state-dot" data-dot aria-hidden="true"></i><span data-state></span></small></div></div><div class="hr-usage"><span data-usage></span><small data-cap></small>${bar(0)}</div>`
        +(item.core?'<div class="hr-locked"><i class="fa fa-lock" aria-hidden="true"></i>Never stop</div>':`<form data-form="policy" class="hr-priority"><label><span class="hr-sr">${esc(item.name)} memory priority</span><select name="level"${editable?'':' disabled'}>${options(item)}</select></label><button class="hr-row-save" type="submit" hidden>Apply</button></form>`)
        +`<button type="button" class="hr-details-toggle" data-expand aria-expanded="false" aria-label="Details for ${esc(item.name)}">Details</button></div><div class="hr-app-details" hidden><span data-actual></span><div class="hr-detail-forms">`
        +(docker?`<form data-form="limit"><label>Memory limit (GiB)<input name="gib" type="number" min="0" step="0.0625" value="${item.limit/1073741824}" required${editable?'':' disabled'}></label><small class="hr-help">0 = no limit. Applied live and kept in the Unraid template.</small><button type="submit"${editable?'':' disabled'}>Set memory limit</button><p class="hr-help" data-limit-warning></p></form>`:'')
        +(!item.core?`<form data-form="policy"><input type="hidden" name="level" value="${p.custom?'custom':p.level}"><label>Reserved RAM (MiB)<input type="number" name="reserve_mib" min="0" max="65536" value="${p.reserve_mib}" required${item.kind==='proc'?' disabled':''}></label><small class="hr-help">Only Never stop / Stop last. A reclaim shield, not extra RAM; combined reservations stay below 25% of RAM.</small>${docker?`<label><input name="whole" type="checkbox"${p.whole?' checked':''}> Stop the whole app if one process runs out</label><label><input name="eligible" type="checkbox"${p.eligible?' checked':''}> Allow a graceful early stop</label><small class="hr-help">Whole-app stopping is off by default. Early stops also need the global switch; protected apps are excluded.</small>`:''}<div class="hr-inline"><button type="submit"${editable?'':' disabled'}>Save options</button>${docker&&editable?'<button type="button" data-clear-priority>Use Docker default</button>':''}</div></form>`:'<p class="hr-help">Core services stay protected. Restarted host services are checked automatically. No container processes are treated as core services.</p>')+'</div></div>';
      return row;
    }
    function paintItems(force=false) {
      if(!latest||!config) return;
      const all=Object.values(latest.items), search=q('[data-filter]').value.trim().toLowerCase();
      for(const kind of ['docker','vm','custom']) q(`[data-count="${kind}"]`).textContent=all.filter(i=>kind==='custom'?i.kind==='docker'&&score(i)!==0:i.kind===kind).length;
      const matching=all.filter(i=>(scope==='custom'?i.kind==='docker'&&score(i)!==0:i.kind===scope)&&i.name.toLowerCase().includes(search));
      matching.sort((a,b)=>(Number(score(b)!==0)-Number(score(a)!==0))||a.name.localeCompare(b.name));
      page=Math.max(0,Math.min(page,Math.ceil(matching.length/pageSize)-1));
      const current=matching.slice(page*pageSize,(page+1)*pageSize), signature=current.map(i=>i.id).join('|');
      if(signature!==visibleIds||force) {
        const fragment=document.createDocumentFragment();
        for(const item of current) { let row=rows.get(item.id); if(!row){row=makeRow(item);rows.set(item.id,row);} fragment.append(row); }
        q('[data-apps]').replaceChildren(fragment); visibleIds=signature;
      }
      for(const item of current) {
        const row=rows.get(item.id); row.querySelector('[data-state]').textContent=item.state;
        row.querySelector('[data-dot]').classList.toggle('running',item.state==='running');
        row.querySelector('[data-usage]').textContent=gib(item.usage); row.querySelector('[data-cap]').textContent=item.kind==='docker'?(item.limit?'/ '+gib(item.limit):'No limit'):'';
        row.querySelector('.hr-usage .hr-bar>span').style.width=Math.min(100,item.usage/(item.limit||latest.sample.total||1)*100)+'%';
        const native=item.kind==='docker';
        row.querySelector('[data-actual]').textContent=(item.adj===null?'Not running':`Live priority ${item.adj}`)+(native?` · ${item.policy.source==='template'?'Saved Unraid template':'Native Docker / Compose'}: ${score(item)} · whole-app stop ${item.whole_actual===1?'on':'off'}`:'')+(native&&item.native_score!==score(item)?' · Protected live now; Docker takes over at the next normal recreation.':'')+(native&&!item.template_available?' · Save a unique Unraid template to edit this app here.':'');
        if(!row.dataset.dirty&&!row.contains(document.activeElement)) {
          const select=row.querySelector('select[name=level]'); if(select&&select.value!==(item.policy.custom?'custom':item.policy.level)) select.innerHTML=options(item);
          for(const input of row.querySelectorAll('.hr-app-details input[name]')) {
            const value=input.name==='gib'?Number((item.limit/1073741824).toFixed(2)):input.name==='level'?(item.policy.custom?'custom':item.policy.level):item.policy[input.name];
            if(value!==undefined) {if(input.type==='checkbox')input.checked=value;else input.value=value;}
          }
        }
        const warning=row.querySelector('[data-limit-warning]'); if(warning) warning.textContent=item.limit&&item.usage>item.limit*.85?'Close to the limit. The kernel may stop a process inside this app.':item.swap_limit==='max'?'This host does not cap this app’s swap separately.':'';
      }
      q('[data-empty]').hidden=matching.length>0;
      q('[data-page-info]').textContent=matching.length?`${page*pageSize+1}–${Math.min((page+1)*pageSize,matching.length)} of ${matching.length}`:'0 apps';
      q('[data-page="-1"]').disabled=page===0; q('[data-page="1"]').disabled=(page+1)*pageSize>=matching.length;
      q('[data-absent]').innerHTML=Object.entries(config.items).filter(([id])=>!latest.items[id]).map(([id,p])=>`<p>${esc(id)} · ${esc(labels[p.level])} <button type="button" data-forget="${esc(id)}">Forget preference</button></p>`).join('')||'<p>No removed apps with saved preferences.</p>';
      for(const [id,row] of rows) if(!latest.items[id]) {row.remove();rows.delete(id);}
    }
    async function loadConfig() {
      if(tile) return;
      const data=await request('config'); config=data.config;
      q('[name=disk_mount]').innerHTML='<option value="">Choose a supported disk</option>'+data.disks.map(d=>`<option value="${esc(d.mount)}"${d.allowed?'':' disabled'}>${esc(d.mount)} (${esc(d.filesystem)})${d.allowed?'':' — unavailable'}</option>`).join('');
      q('[data-disk-reasons]').textContent=data.disks.filter(d=>!d.allowed).map(d=>`${d.mount}: ${d.reason}`).join('. ')||'No unsupported mounted pools found.';
      for(const form of root.querySelectorAll('form[data-form]')) {
        if(['policy','limit'].includes(form.dataset.form)) continue;
        for(const field of form.elements) if(field.name in config) {if(field.type==='checkbox') field.checked=config[field.name];else field.value=config[field.name];}
      }
      q('[data-form=arc] [name=gib]').value=config.arc_max/1073741824;
      rows.clear(); visibleIds=''; if(latest) paintItems(true);
    }
    async function status() { paintStatus(await request('status')); }
    async function activity() {
      if(tile) return;
      const data=await request('events');
      q('[data-events]').innerHTML=data.events.length?data.events.map(e=>`<p class="hr-event"><time>${esc(new Date(e.t*1000).toLocaleString())}</time><b>${esc(e.kind)}</b><span>${esc(e.message)}</span></p>`).join(''):'<p>No activity recorded yet.</p>';
    }
    async function loadHistory() { const data=await request(`history&hours=${tile?1:Number(q('[data-hours]').value)}`); samples=data.samples;historyTime=Date.now();chart(); }
    function reduced(series, value, width) {
      const step=Math.max(1,Math.ceil(series.length/Math.max(32,Math.floor(width/3)))), result=[];
      for(let start=0;start<series.length;start+=step) {
        let low=start,high=start,gap=-1;
        for(let i=start;i<Math.min(start+step,series.length);i++) {const v=value(series[i]);if(!valid(v)){gap=i;continue;}if(!valid(value(series[low]))||v<value(series[low]))low=i;if(!valid(value(series[high]))||v>value(series[high]))high=i;}
        for(const i of [...new Set([low,high,...(gap>=0?[gap]:[])])].sort((a,b)=>a-b)) result.push(series[i]);
      }
      return result;
    }
    function chart() {
      const el=q('[data-chart]'), summary=q('[data-chart-summary]');
      if(!samples.length) {el.textContent='Collecting the first history point…';summary.textContent='Live readings are available above.';return;}
      const key=tile?'available':q('[data-metric]').value, peaks=new Map();
      if(key==='top') for(const sample of samples) for(const [name,value] of Object.entries(sample.top||{})) peaks.set(name,Math.max(peaks.get(name)||0,value));
      const series=key==='top'?[...peaks].sort((a,b)=>b[1]-a[1]).slice(0,8).map(([name])=>name):[key];
      const value=(s,k)=>key==='top'?s.top?.[k]:s[k], values=samples.flatMap(s=>series.map(k=>value(s,k))).filter(valid);
      if(!values.length) {el.textContent=key.startsWith('psi')?'Pressure measurements start after the next reboot, if enabled.':'No readings for this selection.';summary.textContent='Missing readings are not counted as zero.';return;}
      const max=Math.max(...values,1)*1.08, first=samples[0].t, last=samples.at(-1).t;
      const width=Math.max(200,el.clientWidth||400), height=tile?56:156, left=tile?0:47, right=width-(tile?0:8), bottom=height-(tile?3:22), top=8;
      const x=s=>left+(s.t-first)/Math.max(60,last-first)*(right-left), y=v=>bottom-v/max*(bottom-top);
      const fmt=n=>!valid(n)?'Unavailable':key.startsWith('psi')?Number(n).toFixed(1)+'%':gib(n);
      const paths=series.map((name,i)=>{let path='',connected=false,previous=0;for(const s of reduced(samples,s=>value(s,name),width)){const v=value(s,name);if(!valid(v)){connected=false;continue;}path+=`${connected&&s.t-previous<300?'L':'M'}${x(s).toFixed(1)},${y(v).toFixed(1)} `;connected=true;previous=s.t;}const fill=series.length===1&&!path.trim().slice(1).includes('M')?`<path d="${path}L${right},${bottom}H${left}Z" fill="${colors[i]}" opacity=".08"/>`:'';return fill+`<path d="${path}" fill="none" stroke="${colors[i]}" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>`;}).join('');
      const axis=tile?'':`<path d="M${left} ${top}H${right} M${left} ${bottom}H${right}" stroke="currentColor" opacity=".18"/><text x="0" y="14">${esc(fmt(max))}</text><text x="25" y="${bottom+3}">0</text><text x="${left}" y="${height-3}">${esc(new Date(first*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))}</text><text x="${right}" y="${height-3}" text-anchor="end">${esc(new Date(last*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))}</text>`;
      el.innerHTML=`<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="${esc(key==='top'?'Top app memory history':key.replaceAll('_',' ')+' history')}">${axis}${paths}</svg>`;
      summary.innerHTML=key==='top'?'Peak usage: '+series.map((name,i)=>`<span class="hr-legend"><i style="background:${colors[i]}"></i>${esc(name)}</span>`).join(''):`Latest ${esc(fmt(value(samples.at(-1),key)))} · peak ${esc(fmt(Math.max(...values)))} · one point / minute`;
    }
    let chartWidth=0, resizeFrame=0;
    const chartResize=new ResizeObserver(entries=>{const width=Math.round(entries[0].contentRect.width);if(width>0&&width!==chartWidth){chartWidth=width;cancelAnimationFrame(resizeFrame);resizeFrame=requestAnimationFrame(chart);}});chartResize.observe(q('[data-chart]'));
    root.addEventListener('input',e=>{if(e.target.matches('[data-filter]')){page=0;paintItems();}else {const row=e.target.closest('[data-id]');if(row)row.dataset.dirty='true';}});
    root.addEventListener('change',e=>{
      if(e.target.matches('[data-metric]')) chart();
      if(e.target.matches('[data-hours]')) loadHistory().catch(e=>error(e.message));
      if(e.target.matches('.hr-priority select')) {const row=e.target.closest('[data-id]');row.dataset.dirty='true';row.querySelector('.hr-row-save').hidden=false;const hidden=row.querySelector('input[name=level]');if(hidden)hidden.value=e.target.value;}
    });
    root.addEventListener('submit',e=>{
      const form=e.target.closest('form[data-form]');if(!form)return;e.preventDefault();
      const type=form.dataset.form,item=latest?.items[form.closest('[data-id]')?.dataset.id],button=form.querySelector('[type=submit]');let data={};
      if(type==='policy') {
        const policy={level:form.elements.level.value,whole:form.elements.whole?.checked??item.policy.whole,reserve_mib:Number(form.elements.reserve_mib?.value??item.policy.reserve_mib),eligible:form.elements.eligible?.checked??item.policy.eligible};
        if((scores[policy.level] ?? score(item)) >= 0) policy.reserve_mib=0; else policy.eligible=false;
        if(policy.whole&&!item.policy.whole&&!confirm(`Allow one memory-hungry process to stop all of ${item.name}? This may interrupt every task in the app.`))return;
        data={id:item.id,policy};
      } else if(type==='limit') {
        const bytes=Math.round(Number(form.elements.gib.value)*1073741824);data={name:item.name,bytes};
        if(bytes&&bytes<item.usage*1.25&&!confirm(`${item.name} uses ${gib(item.usage)} now. A ${gib(bytes)} limit is close to that. Apply without restarting?`))return;
      } else if(type==='arc')data={bytes:Math.round(Number(form.elements.gib.value)*1073741824)};
      else for(const field of form.elements)if(field.name)data[field.name]=field.type==='checkbox'?field.checked:field.type==='number'?Number(field.value):field.value.trim();
      if(type==='alerts'&&data.act_early&&!config.act_early&&!confirm('Enable graceful early stops for explicitly eligible apps during sustained pressure? Work in that app may be interrupted.'))return;
      change(type,data,button);
    });
    root.addEventListener('click',e=>{
      const scoped=e.target.closest('[data-scope]');if(scoped){scope=scoped.dataset.scope;page=0;for(const button of root.querySelectorAll('[data-scope]'))button.setAttribute('aria-pressed',String(button===scoped));paintItems();return;}
      const paged=e.target.closest('[data-page]');if(paged){page+=Number(paged.dataset.page);paintItems();return;}
      const expanded=e.target.closest('[data-expand]');if(expanded){const details=expanded.closest('[data-id]').querySelector('.hr-app-details');details.hidden=!details.hidden;expanded.setAttribute('aria-expanded',String(!details.hidden));return;}
      const clear=e.target.closest('[data-clear-priority]');if(clear){const id=clear.closest('[data-id]').dataset.id;if(confirm(`Remove the native priority flag for ${latest.items[id].name} and use Normal? No restart.`))change('priority-clear',{id},clear);return;}
      const forget=e.target.closest('[data-forget]');if(forget){if(confirm(`Forget the saved preference for ${forget.dataset.forget}?`))change('forget',{id:forget.dataset.forget},forget);return;}
      const button=e.target.closest('[data-action]');if(!button)return;
      if(button.dataset.action==='events'){activity().catch(e=>error(e.message));return;}
      if(button.dataset.action==='refresh'&&!confirm('Return swapped data to RAM? Headroom refuses unless enough RAM is available. Apps will not be restarted.'))return;
      if(button.dataset.action==='disk-remove'&&!confirm('Remove only the inactive Headroom swap file at '+config.disk_mount+'/.headroom.swap? This cannot be undone.'))return;
      change(button.dataset.action,{},button);
    });
    async function poll() {
      clearTimeout(timer);if(!root.isConnected){chartResize.disconnect();return;}if(inFlight)return;
      inFlight=true;
      try {if(!document.hidden&&(!tile||tileVisible)&&root.getClientRects().length){await Promise.all([status(),!config&&!tile?loadConfig():undefined]);if(Date.now()-historyTime>60000){await loadHistory();if(!tile&&q('#hr-activity').open)await activity();}}}catch(e){error(e.message);}
      finally{inFlight=false;timer=setTimeout(poll,15000);}
    }
    if(tile){
      const observer=new IntersectionObserver(entries=>{const visible=entries[0].isIntersecting;if(visible&&!tileVisible){tileVisible=true;poll();}else tileVisible=visible;});observer.observe(root);
      const header=root.closest('tbody')?.querySelector('.hr-tile-header');
      const bindCollapse=()=>{const collapse=header?.querySelector('.openclose');if(!collapse)return false;collapse.setAttribute('role','button');collapse.setAttribute('tabindex','0');collapse.setAttribute('aria-label','Show or hide Headroom');const sync=()=>collapse.setAttribute('aria-expanded',String(collapse.classList.contains('fa-chevron-up')));sync();collapse.addEventListener('click',()=>{sync();poll();});collapse.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();collapse.click();}});return true;};
      if(header&&!bindCollapse()){const controls=new MutationObserver(()=>{if(bindCollapse())controls.disconnect();});controls.observe(header,{childList:true,subtree:true});}
    } else q('#hr-activity').addEventListener('toggle',()=>{if(q('#hr-activity').open)activity().catch(e=>error(e.message));});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)poll();});
    poll();
  }
})();
