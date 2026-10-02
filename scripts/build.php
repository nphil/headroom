#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=dirname(__DIR__); chdir($root);
$version=$argv[1]??trim(file_get_contents('src/version'));
if(!preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d{1,3}$/D',$version)) throw new RuntimeException('Version must be YYYY.MM.DD.N.');
$notes=isset($argv[2])?trim(file_get_contents($argv[2])):'Native memory protection, compressed swap, safe controls and early warnings.';
if($notes==='' || strlen($notes)>20000) throw new RuntimeException('Provide 1–20000 bytes of release notes.');
file_put_contents('src/version',$version."\n");
if(!is_dir('dist')) mkdir('dist');
$archive="dist/headroom-$version.tar.gz";
$cmd=['tar','--sort=name','--mtime=@0','--owner=0','--group=0','--numeric-owner','-czf',$root.'/'.$archive,'-C',$root.'/src','.','-C',$root,'LICENSE','NOTICE','README.md'];
$p=proc_open($cmd,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
if(!is_resource($p) || proc_close($p)!==0) throw new RuntimeException('Package build failed.');
$sha=hash_file('sha256',$archive); $md5=hash_file('md5',$archive);
$changes="## $version\n$notes\n";
$template=file_get_contents('headroom.plg.in');
$xml=strtr($template,['@VERSION@'=>$version,'@SHA256@'=>$sha,'@MD5@'=>$md5,'@CHANGES@'=>str_replace(']]>',']]]]><![CDATA[>',$changes)]);
libxml_use_internal_errors(true);
if(!simplexml_load_string($xml)) throw new RuntimeException('Generated plugin XML is invalid.');
file_put_contents('headroom.plg',$xml); file_put_contents('dist/headroom.plg',$xml);
file_put_contents('dist/SHA256SUMS',$sha.'  '.basename($archive)."\n".hash('sha256',$xml)."  headroom.plg\n");
$log=is_file('CHANGELOG.md')?file_get_contents('CHANGELOG.md'):"# Changelog\n\n";
if(!str_contains($log,"## $version\n")) file_put_contents('CHANGELOG.md',str_replace("# Changelog\n\n","# Changelog\n\n".$changes."\n",$log));
echo "$archive\nSHA256 $sha\n";
