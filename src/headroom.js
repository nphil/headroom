(() => {
  'use strict';
  for (const root of document.querySelectorAll('[data-headroom]:not([data-ready])')) {
    root.dataset.ready = 'true';
    const tile = root.dataset.headroom === 'tile';
    const q = s => root.querySelector(s);
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const gib = n => n!==null&&n!==undefined&&n!==false&&Number.isFinite(Number(n)) ? (Number(n)/1073741824).toFixed(1)+' GiB' : 'Unavailable';
    const labels = {never:'Never stop',last:'Stop last',normal:'Normal',early:'Stop early',first:'Stop first'};
    const scores = {never:-1000,last:-500,normal:0,early:500,first:1000};
    let config = null, latest = null, samples = [], timer = null, historyTime = 0, actionError = '';
    const rows = new Map();
    async function request(action, body) {
      const opts = {credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.csrf},signal:AbortSignal.timeout(body ? 180000 : 15000)};
      if (body !== undefined) { opts.method='POST'; opts.headers['Content-Type']='application/x-www-form-urlencoded'; opts.body=new URLSearchParams({csrf_token:root.dataset.csrf,payload:JSON.stringify(body)}); }
      const r = await fetch(`/plugins/headroom/api.php?action=${action}`, opts);
      let data; try { data=await r.json(); } catch { throw Error('Unraid did not return a Headroom response. Reload to check your login.'); }
      if (!r.ok || !data.ok) throw Error(data.error || 'The request could not be completed.');
      return data;
    }
    function error(text) { q('[data-error]').textContent=text; q('[data-error]').hidden=!text; }
    function feedback(text) { const el=q('[data-feedback]'); if(el) { el.textContent=text; el.scrollIntoView({block:'nearest'}); } }
    async function change(action, data, button) {
      if(button) button.disabled=true;
      actionError='';
      feedback('Applying… No page refresh needed.');
      try { const result=await request(action,data); feedback(result.message); error(''); await loadConfig(); await status(); await activity(); }
      catch(e) { actionError=e.message; error(actionError); feedback('Not completed. Check the message and current readings before retrying.'); }
      finally { if(button) button.disabled=false; }
    }
    function stat(label, value, detail, used, total) {
      const percent=total ? Math.max(0,Math.min(100,used/total*100)) : 0;
      return `<div class="hr-stat"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(detail)}</small>${total?`<div class="hr-meter" role="meter" aria-label="${esc(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent.toFixed(1)}"><span style="width:${percent}%"></span></div>`:''}</div>`;
    }
    function paintStatus(data) {
      latest=data; const s=data.sample;
      q('[data-health]').textContent=data.stale?'Readings are out of date':data.health;
      q('[data-updated]').textContent=`Updated ${new Date(data.updated*1000).toLocaleTimeString()} · ${data.watchers.includes('docker')&&data.watchers.includes('kernel')?'Automatic protection and stop detection active':'Automatic monitoring not fully connected'}`;
      const warnings=Object.values(data.errors||{}); if(data.stale) warnings.unshift('The monitor has not supplied a fresh reading for over 45 seconds.');
      error([actionError,...warnings].filter(Boolean).join(' '));
      q('[data-stats]').innerHTML=stat('Available RAM',gib(s.available),gib(s.total)+' physical · '+gib(s.ram_used)+' in use',s.available,s.total)
        +stat('Swap in use',gib(s.swap_used),`${gib(s.swap_size)} capacity · ${gib(s.zram_ram)} RAM cost`,s.swap_used,s.swap_size)
        +stat('ZFS cache',gib(s.arc_size),`${gib(s.arc.max)} maximum`,s.arc_size,s.arc.max)
        +stat('Waiting for RAM',s.psi_some===null?'Not active':s.psi_some.toFixed(1)+'%',s.psi_full===null?(data.psi_requested?'Activates after the next reboot':'Pressure statistics are off'):'All tasks stalled: '+s.psi_full.toFixed(1)+'%');
      if(tile) return;
      q('[data-explain]').textContent=data.explain;
      q('[data-psi-note]').textContent=s.psi_some===null ? (data.psi_requested?'Activates after the next reboot. Unraid needs one planned restart to begin measuring memory delays. RAM, swap and OOM alerts are already active.':'Pressure statistics are off. RAM, swap and memory-kill alerts remain available.') : (data.psi_requested?'Active: measuring memory delays now.':'Statistics are active now and will switch off after the next reboot.');
      q('[data-zram-live]').textContent=s.zram?.device ? s.zram.device+' · '+gib(s.zram.size)+' configured · '+gib(s.zram.saved)+' RAM saved by compression' : 'No Headroom-owned compressed swap device.';
      q('[data-arc-live]').textContent='Now '+gib(s.arc.size)+' · target '+gib(s.arc.target)+' · maximum '+gib(s.arc.max)+' · cache hit rate '+(s.arc.hit_percent===null?'unavailable':s.arc.hit_percent.toFixed(1)+'%')+'.';
      q('[data-ramdisks]').innerHTML=Object.entries(s.ramdisks).map(([path,v])=>'<p><b>'+esc(path)+'</b>: '+gib(v.used)+' allocated on its filesystem; directory files '+(s.footprints?.bytes?.[path]==null?'not yet measured':gib(s.footprints.bytes[path]))+'.</p>').join('');
      if(config) paintItems(data.items);
    }
    function options(level) { return Object.entries(labels).map(([v,label])=>`<option value="${v}"${v===level?' selected':''}>${label}</option>`).join(''); }
    function makeRow(item) {
      const p=item.policy; const docker=item.kind==='docker';
      const row=document.createElement('div'); row.className='hr-app'; row.dataset.id=item.id;
      row.innerHTML=`<div><strong>${esc(item.name)}</strong><span data-usage class="hr-app-usage"></span><small data-actual></small></div>`
        +(item.core?'<div><b>Never stop</b><p class="hr-help">Core service — locked</p></div>':`<form data-form="policy"><label>Memory preference <select name="level">${options(p.level)}</select></label><details><summary>More options</summary><label>Reservation (MiB) <input type="number" name="reserve_mib" min="0" max="65536" value="${p.reserve_mib}" required></label>${docker?`<label><input name="whole" type="checkbox"${p.whole?' checked':''}> Stop whole app if one process runs out</label><label><input name="eligible" type="checkbox"${p.eligible?' checked':''}> Allow early stop</label>`:''}</details><button type="submit">Save preference</button></form>`)
        +(docker?`<form data-form="limit"><label>Memory limit (GiB) <input name="gib" type="number" min="0" step="0.0625" value="${item.limit/1073741824}" required></label><small>0 = no limit. Live + saved in template.</small><button type="submit">Set limit</button><small data-limit-warning></small></form>`:'');
      return row;
    }
    function paintItems(items) {
      for(const [id,row] of rows) if(!items[id]) { row.remove(); rows.delete(id); }
      const sorted=Object.values(items).sort((a,b)=>a.name.localeCompare(b.name));
      for(const item of sorted) {
        let row=rows.get(item.id);
        if(!row) { row=makeRow(item); rows.set(item.id,row); q(item.kind==='docker'?'[data-apps]':item.kind==='vm'?'[data-vms]':'[data-services]').append(row); }
        row.querySelector('[data-usage]').textContent=`${item.state} · ${gib(item.usage)}${item.kind==='docker'?(item.limit?` / ${gib(item.limit)} limit`:' · no limit'):''}`;
        row.querySelector('[data-actual]').textContent=item.adj===null?'Not running':`Applied score ${item.adj}${item.adj!==scores[item.policy.level]?' — waiting for re-apply':''}${item.kind==='docker'?` · whole app: ${item.whole_actual===1?'on':'off'}`:''}`;
        const warning=row.querySelector('[data-limit-warning]');
        if(warning) warning.textContent=item.limit&&item.usage>item.limit*.85?'Close to the limit. The kernel may stop a process inside this app.':item.swap_limit==='max'?'This host does not cap this app’s swap separately.':'';
      }
      for(const [selector,kind] of [['[data-vms]','vm'],['[data-apps]','docker']]) if(!Object.values(items).some(i=>i.kind===kind)) q(selector).textContent=`No ${kind==='vm'?'virtual machines':'containers'} found.`;
      q('[data-absent]').innerHTML=Object.entries(config.items).filter(([id])=>!items[id]).map(([id,p])=>`<p>${esc(id)} — ${esc(labels[p.level])} <button type="button" data-forget="${esc(id)}">Forget preference</button></p>`).join('')||'<p>No absent apps with saved preferences.</p>';
      filter();
    }
    function filter() { const value=q('[data-filter]')?.value.toLowerCase()||''; for(const [id,row] of rows) row.hidden=!id.toLowerCase().includes(value); }
    async function loadConfig() {
      if(tile) return;
      const data=await request('config'); config=data.config;
      const disks=q('[name=disk_mount]'); disks.innerHTML='<option value="">Choose a supported disk</option>'+data.disks.map(d=>'<option value="'+esc(d.mount)+'"'+(d.allowed?'':' disabled')+'>'+esc(d.mount)+' ('+esc(d.filesystem)+')'+(d.allowed?'':' — unavailable')+'</option>').join('');
      q('[data-disk-reasons]').textContent=data.disks.filter(d=>!d.allowed).map(d=>`${d.mount}: ${d.reason}`).join('. ')||'No unsupported mounted pools found.';
      for(const form of root.querySelectorAll('form[data-form]')) {
        if(['policy','limit'].includes(form.dataset.form)) continue;
        for(const field of form.elements) if(field.name in config) { if(field.type==='checkbox') field.checked=config[field.name]; else field.value=config[field.name]; }
      }
      q('[data-form=arc] [name=gib]').value=config.arc_max/1073741824;
      for(const row of rows.values()) row.remove(); rows.clear();
      if(latest) paintItems(latest.items);
    }
    async function status() { const data=await request('status'); paintStatus(data); }
    async function activity() {
      if(tile) return;
      const data=await request('events');
      q('[data-events]').innerHTML=data.events.length?data.events.map(e=>`<p class="hr-event"><time>${esc(new Date(e.t*1000).toLocaleString())}</time><b>${esc(e.kind)}</b><span>${esc(e.message)}</span></p>`).join(''):'<p>No activity recorded yet.</p>';
    }
    async function loadHistory() { const hours=tile?1:Number(q('[data-hours]').value); const data=await request(`history&hours=${hours}`); samples=data.samples; historyTime=Date.now(); chart(); }
    function chart() {
      const el=q('[data-chart]'), summary=q('[data-chart-summary]');
      if(!samples.length) { el.textContent='The first history point is being collected.'; summary.textContent='Live readings above are still available.'; return; }
      const key=tile?'available':q('[data-metric]').value;
      const peaks=new Map();
      if(key==='top') for(const sample of samples) for(const [name,value] of Object.entries(sample.top||{})) peaks.set(name,Math.max(peaks.get(name)||0,value));
      const series=key==='top'?[...peaks].sort((a,b)=>b[1]-a[1]).slice(0,8).map(([name])=>name):[key];
      const colors=['var(--lu-accent)','#379bd6','#54a96b','#b087db','#c59131','#d17fa1','#52abab','#9e9e9e'];
      const val=(s,k)=>key==='top'?s.top?.[k]:s[k];
      const values=samples.flatMap(s=>series.map(k=>val(s,k))).filter(v=>v!==null&&v!==undefined);
      if(!values.length) { el.textContent=key.startsWith('psi')?'No pressure measurements yet — activates after the next reboot if enabled.':'No measurements for this selection.'; summary.textContent='Missing readings are not treated as zero.'; return; }
      const max=Math.max(...values,1)*1.08, first=samples[0].t, last=samples.at(-1).t;
      const width=Math.max(300,Math.min(900,el.clientWidth||900)), right=width-20, plotWidth=right-56;
      const fmt=n=>n===null||n===undefined?'Unavailable':key.startsWith('psi')?Number(n).toFixed(1)+'%':gib(n);
      const paths=series.map((name,i)=>{ let path='', connected=false; for(const s of samples) { const v=val(s,name); if(v===null||v===undefined) {connected=false;continue;} const x=56+(s.t-first)/Math.max(60,last-first)*plotWidth,y=151-v/max*130; path+=`${connected?'L':'M'}${x.toFixed(1)},${y.toFixed(1)} `;connected=true; } const end=val(samples.at(-1),name); return `<path d="${path}" fill="none" stroke="${colors[i]}" stroke-width="2"/>`+(end===null||end===undefined?'':`<circle cx="${56+(last-first)/Math.max(60,last-first)*plotWidth}" cy="${151-end/max*130}" r="3" fill="${colors[i]}"/>`); }).join('');
      el.innerHTML=`<svg viewBox="0 0 ${width} 180" role="img" aria-label="${esc(key==='top'?'Top container memory history':key.replaceAll('_',' ')+' history')}"><path d="M56 18V151H${right}" fill="none" stroke="currentColor" opacity=".3"/><text x="0" y="24">${esc(fmt(max))}</text><text x="25" y="154">0</text>${paths}<text x="56" y="177">${esc(new Date(first*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))}</text><text x="${right}" y="177" text-anchor="end">${esc(new Date(last*1000).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}))}</text></svg>`;
      summary.innerHTML=key==='top'?'Highest usage in this period: '+series.map((name,i)=>`<span class="hr-legend"><i style="background:${colors[i]}"></i>${esc(name)}</span>`).join(''):`${samples.length} recorded points · latest ${esc(fmt(val(samples.at(-1),key)))} · peak ${esc(fmt(Math.max(...values,0)))}`;
    }
    let chartWidth=0;
    const chartResize=new ResizeObserver(entries=>{ const width=Math.round(entries[0].contentRect.width); if(width!==chartWidth) {chartWidth=width;chart();} });
    chartResize.observe(q('[data-chart]'));
    root.addEventListener('input',e=>{ if(e.target.matches('[data-filter]')) filter(); });
    root.addEventListener('change',e=>{ if(e.target.matches('[data-metric]')) chart(); if(e.target.matches('[data-hours]')) loadHistory().catch(e=>error(e.message)); });
    root.addEventListener('submit',e=>{
      const form=e.target.closest('form[data-form]'); if(!form) return; e.preventDefault();
      const type=form.dataset.form, item=latest?.items[form.closest('[data-id]')?.dataset.id], button=form.querySelector('[type=submit]'); let data={};
      if(type==='policy') {
        const policy={level:form.elements.level.value,whole:form.elements.whole?.checked||false,reserve_mib:Number(form.elements.reserve_mib.value),eligible:form.elements.eligible?.checked||false};
        if(policy.whole&&!item.policy.whole&&!confirm(`Allow one memory-hungry process to stop all of ${item.name}? This may interrupt every task in the app.`)) return;
        data={id:item.id,policy};
      } else if(type==='limit') {
        const bytes=Math.round(Number(form.elements.gib.value)*1073741824); data={name:item.name,bytes};
        if(bytes&&bytes<item.usage*1.25&&!confirm(`${item.name} currently uses ${gib(item.usage)}. A ${gib(bytes)} limit is close to that. Apply live without restarting?`)) return;
      } else if(type==='arc') data={bytes:Math.round(Number(form.elements.gib.value)*1073741824)};
      else for(const field of form.elements) if(field.name) data[field.name]=field.type==='checkbox'?field.checked:field.type==='number'?Number(field.value):field.value.trim();
      if(type==='alerts'&&data.act_early&&!config.act_early&&!confirm('Enable graceful early stops for explicitly eligible apps during sustained memory pressure? Work in the chosen app may be interrupted.')) return;
      change(type,data,button);
    });
    root.addEventListener('click',e=>{
      const forget=e.target.closest('[data-forget]'); if(forget) { if(confirm(`Forget the saved preference for ${forget.dataset.forget}?`)) change('forget',{id:forget.dataset.forget},forget); return; }
      const button=e.target.closest('[data-action]'); if(!button) return;
      if(button.dataset.action==='events') { activity().catch(e=>error(e.message)); return; }
      if(button.dataset.action==='refresh'&&!confirm('Return swapped data to RAM? Headroom will refuse unless enough RAM is available. Apps will not be restarted.')) return;
      if(button.dataset.action==='disk-remove'&&!confirm('Remove only the inactive Headroom swap file at '+config.disk_mount+'/.headroom.swap? This deletion cannot be undone.')) return;
      change(button.dataset.action,{},button);
    });
    async function poll() {
      clearTimeout(timer); if(!root.isConnected) return;
      if(!document.hidden) try { if(!config&&!tile) await loadConfig(); await status(); if(Date.now()-historyTime>60000) { await loadHistory(); await activity(); } } catch(e) { error(e.message); }
      timer=setTimeout(poll,15000);
    }
    document.addEventListener('visibilitychange',()=>{ if(!document.hidden) poll(); });
    poll();
  }
})();
