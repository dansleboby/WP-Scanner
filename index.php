<?php 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$cur = exec('ps -Al | grep ruby | wc -l');
$max = 10;

require_once  __DIR__.'/vendor/autoload.php';
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use SensioLabs\AnsiConverter\AnsiToHtmlConverter;

function base64url_encode($data) { 
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); 
} 

function base64url_decode($data) { 
  return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT)); 
} 

if(isset($_POST) && isset($_POST['g-recaptcha-response']) && isset($_POST['url'])) {
	
	if($cur >= 10) {
		header('Location: ?err='.urlencode('Max scan is run try later'));
		exit();
	}
	if(filter_var($_POST['url'], FILTER_VALIDATE_URL)) {
		$url = 'https://www.google.com/recaptcha/api/siteverify';
		$data = array(
			'secret' => '6LeJ1D4UAAAAAEUhxb3qGvaG2aNxXOuWJitz03Ud',
			'response' => $_POST["g-recaptcha-response"]
		);
		$options = array(
			'http' => array (
				'method' => 'POST',
				'content' => http_build_query($data)
			)
		);
		$context  = stream_context_create($options);
		$verify = @file_get_contents($url, false, $context);
		$captcha_success=json_decode($verify);
		if ($captcha_success->success==false) {
			header('Location: ?err='.urlencode('You are a rebot'));
			exit();
		} else {
			$url = $_POST['url'];
			
			$filename = 'reports/'.md5($url).'.log';
			if(file_exists($filename))
				unlink($filename);
			
			exec('ruby /var/www/html/wpscan/wpscan.rb --update --follow-redirection --url "'.$url.'" --log /var/www/html/reports/'.md5($url).'.log > /dev/null &');
			header('Location: ?view='.base64url_encode($_POST['url']));
			exit();
		}
	} else {
		header('Location: ?err='.urlencode("URL is not valid!"));
		exit();
	}
}

if (isset($_GET['ajax'])) {
  $sessionID = 'log'.$_GET['s'];
  session_id($sessionID);
  session_start();
  
  $converter = new AnsiToHtmlConverter();

  $filename = 'reports/'.md5(base64url_decode($_GET['view']));
  
  $handle = fopen($filename.'.log', 'r');
  if (isset($_GET['offset'])) {
    $data = stream_get_contents($handle, -1, $_GET['offset']);
	header('Content-type: text/json');
    echo json_encode(['html' => $converter->convert($data), 'offset' => ftell($handle)]);
  } else {
	$data = stream_get_contents($handle, -1);
    fseek($handle, 0, SEEK_END);
	header('Content-type: text/json');
	echo json_encode(['html' => $converter->convert($data), 'offset' => ftell($handle)]);
  }
  exit();
}

if(isset($_GET['view']) && isset($_GET['download'])) {
	$filename = 'reports/'.md5(base64url_decode($_GET['view']));
	
	header('Content-Type: application/octet-stream');
	header("Content-Transfer-Encoding: Binary"); 
	header("Content-disposition: attachment; filename=\"" . base64url_decode($_GET['view']) . ".txt\""); 
	echo preg_replace('#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', file_get_contents($filename.".log"));
	exit();
}

$randomSession = rand();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>WP Scanner - Free online wordpress vulnerability check</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="WP-Scanner.ca is an online WordPress security scan for detecting and reporting WordPress vulnerabilities.">
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <link rel="stylesheet" href="style.css" media="screen">
  <link rel="icon" type="image/x-icon" href="favicon.ico" />
  
  <!-- Global site tag (gtag.js) - Google Analytics -->
  <script async src="https://www.googletagmanager.com/gtag/js?id=UA-17748459-26"></script>
  <script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());

	gtag('config', 'UA-17748459-26');
  </script>

  <script src="https://code.jquery.com/jquery-1.8.2.min.js"></script>
  <script src='https://www.google.com/recaptcha/api.js'></script>

</head>
<body>
	<main role="main" class="container">
		<div>
			<a href="/" style="display: block;text-align: center;"><img alt="Wp-Scanner Logo" src="logo-full.svg" style="max-width: 500px;"/></a>
			<h1 class="text-center mt-3">WP Scanner - Free online wordpress vulnerability check!</h1>
			<h2 class="text-center mt-3">An online version of wpscan.org to scan your site online for vulnerability!</h2>
			
			<div class="text-center"><small>Server load (<?=$cur?>/<?= $max ?>)</small></div>
			<div class="progress mb-4">
			  <div class="progress-bar bg-success" style="width:<?=(($cur/$max)*100)?>%"></div>
			</div>
			
			
			<?php if(isset($_GET['err'])): ?>
			<div class="alert alert-danger">
				<?= $_GET['err']; ?>
			</div>
			<?php endif; ?>
			
			<?php if(isset($_GET['view'])): ?>
			<h2 class="my-4">Scan of <?= base64url_decode($_GET['view']) ?></h2>
			<pre id="tail" style="background-color: black;overflow: auto;padding: 10px 15px;font-family: monospace;max-height: 350px;overflow-y: auto;"></pre>
			<div class="text-center my-2" id="progress"><img src="ajax-loader.gif" /></div>
			<div class="my-3 text-center" id="pdf" style="display:none">
				<a href="/" class="btn btn-lg btn-danger">Back</a>
				<a href="?view=<?= $_GET['view'] ?>&download" class="btn btn-lg btn-success">Download</a>
			</div>
			<script>
				function refresh() {
				  $.get('?ajax&s=<?=$randomSession;?>&offset='+offset+'&view=<?=$_GET['view']?>', function(data) {
						$('#tail').append(data.html);
						offset = data.offset;
						var objDiv = document.getElementById("tail");
						objDiv.scrollTop = objDiv.scrollHeight;
						
						if(data.html.indexOf('Elapsed time') != -1 
						|| data.html.indexOf('but does not seem to be running WordPress') != -1 
						|| data.html.indexOf('seems to be down. Maybe the site is blocking') != -1 
						|| data.html.indexOf('The target is responding with a') != -1
						|| data.html.indexOf('[Y]es [N]o, default: [N]') != -1
						|| data.html.indexOf('We do not support scanning *.wordpress.com hosted blogs') != -1) {
							clearInterval(interval);
							$("#pdf").show();
							$("#progress").hide();
						}
					});
				}
			
				var offset = 0;
				var interval = setInterval(refresh, 1000);
			</script>
			<?php else: ?>
			<form action="?process" method="POST" class="form-horizontal text-center" id="form">
				<input id="url" name="url" type="url" placeholder="https://exemple.com" class="form-control form-control-lg" required autocomplete="off" />
				<div class="g-recaptcha my-3" data-sitekey="6LeJ1D4UAAAAABinSC8IyDUv7WtIfaGSA8kzn_6Z"></div>
				<button type="submit" class="btn btn-success btn-lg my-3">Submit</button>
			</form>
			<?php endif; ?>
		</div>
    </main>
	<a href="https://wpscan.org/" class="wpslogo">Propulsed by: <img alt="WPScan logo" src="https://raw.githubusercontent.com/wpscanteam/wpscan/gh-pages/wpscan_logo_407x80.png"></a>
	<!-- Go to www.addthis.com/dashboard to customize your tools -->
	<script type="text/javascript" src="//s7.addthis.com/js/300/addthis_widget.js#pubid=ra-4dffd1494ca4da71"></script>
</body>
</html>