<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// --game-dir targets the Apache-served copy, so edited database settings match.
$options=getopt('',['once','batch:','interval:','seed','game-dir:','help','check']);
$game=(string)($options['game-dir']??__DIR__.'/../public_html');
$worker=rtrim($game,'/\\').'/includes/tamer_worker.php';
if(!is_file($worker)){fwrite(STDERR,"Tamer worker was not found. Set --game-dir to the installed game folder.\n");exit(1);}
require $worker;
