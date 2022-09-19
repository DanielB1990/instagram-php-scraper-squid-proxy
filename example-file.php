<?php
declare(strict_types=1);

# https://github.com/pgrimaud/instagram-user-feed

require '/var/www/html/vendor/autoload.php';

$skipSpecificShortcodes = array();
$allowScraping=0;

$cacheFolder = "/var/www/html/cache/";
$mediaFolder = $cacheFolder."instagram-media/";

if (!is_dir($mediaFolder)) {
	mkdir($mediaFolder, 0755, true);
}

$scanned_directory = array_diff(scandir($mediaFolder), array('..', '.'));
$skipSpecificShortcodes = array_unique(array_merge($skipSpecificShortcodes, $scanned_directory), SORT_REGULAR);

function downloadFile($url,$filename){
	$fp = fopen($filename,'w');
	if($fp){
		$ch = curl_init ($url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_VERBOSE, false);
		curl_setopt($ch, CURLOPT_USERAGENT,'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36');
		$result = parse_url($url);

		$raw=curl_exec($ch);
		curl_close ($ch);
		if($raw){
			fwrite($fp, $raw);
		}
		fclose($fp);
		if(!$raw){
			@unlink($filename);
			return false;
		}
		return true;
	}
	return false;
}

# composer require guzzlehttp/guzzle pgrimaud/instagram-user-feed tedivm/fetch monolog/monolog spatie/image-optimizer

use Fetch\Server;
use Fetch\Message;

use GuzzleHttp\Client;
use Instagram\Api;
use Instagram\Auth\Checkpoint\ImapClient;
use Instagram\Exception\InstagramException;

use Psr\Cache\CacheException;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

use Spatie\ImageOptimizer\OptimizerChainFactory;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$appName = 'insta-scraper';
$logFile = '/tmp/'.$appName.'.log';

$logger = new Logger($appName);
$logger->pushHandler(new StreamHandler($logFile, Logger::DEBUG));
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$mailServerConn = new \Fetch\Server('mailhost.domainname.ext', 993);
$mailServerConn->setAuthentication('changedetection@domain.ext', 'MASKEDPASSWORD');

$messages = $mailServerConn->getMessages();
$countedMessages = count($messages);
krsort($messages);

if($countedMessages != 0) {
	$msgCounter=0;
	foreach($messages as $message) {
		$msgCounter++;
		$logger->info('getMessage: '.$msgCounter);

		$logger->info('getAddresses: '.trim($message->getAddresses('from')['address']));
		$elements = imap_mime_header_decode($message->getSubject());
		$getSubject = trim($elements[0]->text);
		$logger->info('getSubject: '.$getSubject);

		if(trim($message->getAddresses('from')['address']) == 'changedetection@domain.ext' && $getSubject == 'ChangeDetection.io - https://www.instagram.com/INSTAGRAMUSERNAME-YOU-WANT-TO-SCRAPE/') {
			$allowScraping=1;

            $message->delete();
		}
	}
}

$mailServerConn->expunge();
imap_close($mailServerConn->getImapStream());

if($allowScraping == 0){
	$logger->alert('allowScraping: '.$allowScraping.' (die)');
	die;
} else {
	$logger->alert('allowScraping: '.$allowScraping.' (GO!)');
}

$logger->debug("skipSpecificShortcodes", $skipSpecificShortcodes);

$cachePool = new FilesystemAdapter('instagram', 0, $cacheFolder);
$instagramArray = array();

$server = new Server('mailhost.domainname.ext', 587);
$server->setAuthentication('instagram@domain.ext', 'MASKEDPASSWORD2');

$messages = $server->getMessages();
foreach ($messages as $message) {
	$message->delete();
}
$server->expunge();

sleep(1);

try {
	$client = new Client([
		'proxy' => [
			'http' => 'RASPBERRYPI-IPADDRESS:3128',
			'https' => 'RASPBERRYPI-IPADDRESS:3128'
		]
	]);

	$data = $client->request('GET', 'https://api.ipify.org?format=json', [
		'headers' => [
			'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36'
		]
	]);

	$dataIp = json_decode((string)$data->getBody());
	$logger->info('IP for Instagram requests : '.$dataIp->ip);

	$dataRealIp = json_decode(file_get_contents('https://api.ipify.org?format=json'));
	$logger->info('Your IP : ' . $dataRealIp->ip);

	$api = new Api($cachePool, $client);
	$api->setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Safari/537.36');

	$imapClient = new ImapClient('mailhost.domainname.ext:587', 'instagram@domain.ext', 'MASKEDPASSWORD2');
	$api->login('INSTAGRAMUSERNAME', 'INSTAGRAMUSERPASSWORD', $imapClient, 15);

	$profile = $api->getProfile('INSTAGRAMUSERNAME-YOU-WANT-TO-SCRAPE');

	$medias = $profile->getMedias();

	foreach($medias AS $mediaKey => $mediaValue){
		if(isset($postInfo)){
			unset($postInfo);
			$postInfo = array();
		}
		if(isset($downloadURLs)){
			unset($downloadURLs);
			$downloadURLs = array();
		}
		$postInfo = $mediaValue->toArray();
		$logger->info("Shortcode: ".$postInfo['shortcode']);

		if(in_array($postInfo['shortcode'], $skipSpecificShortcodes)){
			continue;
		}
		$logger->debug("postInfo", $postInfo);

		$postInfo['caption'] = $postInfo['caption'].' ';
		foreach($postInfo['hashtags'] AS $hashtag){
			$postInfo['caption'] = preg_replace("/\\".$hashtag."\\b/", '', $postInfo['caption']);
		}
		$trimCaptionString = ucfirst(trim($postInfo['caption']));

		$tagsArray = array();
		foreach($postInfo['hashtags'] AS $hashtag){
			$tagsArray[] = strtolower(trim(str_replace('#', '', $hashtag)));
		}

		$instagramArray[] = array(
			'shortcode' => $postInfo['shortcode'],
			'createdTime' => $mediaValue->getDate()->format('Y-m-d h:i:s'),
			'createdTime-ts' => strtotime($mediaValue->getDate()->format('Y-m-d h:i:s')),
			'caption' => $trimCaptionString,
			'link' => $postInfo['link'],
			'video' => $postInfo['video'],
			'videoUrl' => $postInfo['videoUrl'],
			'thumbnails' => $postInfo['thumbnails'],
			'thumbnailSrc' => $postInfo['thumbnailSrc'],
			'displaySrc' => $postInfo['displaySrc'],
			'typeName' => $postInfo['typeName'],
			'tags' => array_values($tagsArray)
		);

		$save_folder = $mediaFolder.$postInfo['shortcode'].'/';
		if (!file_exists($save_folder)) {
			mkdir($save_folder, 0755, true);
		}

		$arrayI=0;

		$basename = basename($postInfo['displaySrc']);
		$exp = explode('?',$basename);
		$pathinfo = pathinfo($exp[0]);

		$filename = 'displaySrc.'.$pathinfo['extension'];
		$downloadURLs[$arrayI]['url'] = $postInfo['displaySrc'];
		$downloadURLs[$arrayI]['saveas'] = $filename;

		if(trim($postInfo['videoUrl']) != ''){
			$arrayI++;
			$basename = basename($postInfo['videoUrl']);
			$exp = explode('?',$basename);
			$pathinfo = pathinfo($exp[0]);

			$filename = 'video.'.$pathinfo['extension'];
			$downloadURLs[$arrayI]['url'] = $postInfo['videoUrl'];
			$downloadURLs[$arrayI]['saveas'] = $filename;
		}

		foreach($downloadURLs as $key => $value){
			$savepath = $save_folder.$value['saveas'];

			if (!file_exists($savepath)) {
				if(downloadFile($value['url'], $savepath)) {
					$logger->info($value['saveas']." - file downloaded successfully to ".$save_folder);
					$optimizerChain = OptimizerChainFactory::create();
					$optimizerChain->optimize($savepath);
				} else {
					$logger->info($value['saveas']." - file downloading failed to ".$save_folder);
				}
			}
		}
	}

	do {
		$profile = $api->getMoreMedias($profile);

		$medias = $profile->getMedias();

		foreach($medias AS $mediaKey => $mediaValue){
			if(isset($postInfo)){
				unset($postInfo);
				$postInfo = array();
			}
			if(isset($downloadURLs)){
				unset($downloadURLs);
				$downloadURLs = array();
			}
			$postInfo = $mediaValue->toArray();
			$logger->info("Shortcode: ".$postInfo['shortcode']);

			if(in_array($postInfo['shortcode'], $skipSpecificShortcodes)){
				continue;
			}
			$logger->debug("postInfo", $postInfo);

			$postInfo['caption'] = $postInfo['caption'].' ';
			foreach($postInfo['hashtags'] AS $hashtag){
				$postInfo['caption'] = preg_replace("/\\".$hashtag."\\b/", '', $postInfo['caption']);
			}
			$trimCaptionString = ucfirst(trim($postInfo['caption']));

			$tagsArray = array();
			foreach($postInfo['hashtags'] AS $hashtag){
				$tagsArray[] = strtolower(trim(str_replace('#', '', $hashtag)));
			}

			$instagramArray[] = array(
				'shortcode' => $postInfo['shortcode'],
				'createdTime' => $mediaValue->getDate()->format('Y-m-d h:i:s'),
				'createdTime-ts' => strtotime($mediaValue->getDate()->format('Y-m-d h:i:s')),
				'caption' => $trimCaptionString,
				'link' => $postInfo['link'],
				'video' => $postInfo['video'],
				'videoUrl' => $postInfo['videoUrl'],
				'thumbnails' => $postInfo['thumbnails'],
				'thumbnailSrc' => $postInfo['thumbnailSrc'],
				'displaySrc' => $postInfo['displaySrc'],
				'typeName' => $postInfo['typeName'],
				'tags' => array_values($tagsArray)
			);

			$save_folder = $mediaFolder.$postInfo['shortcode'].'/';
			if (!file_exists($save_folder)) {
				mkdir($save_folder, 0755, true);
			}

			$arrayI=0;

			$basename = basename($postInfo['displaySrc']);
			$exp = explode('?',$basename);
			$pathinfo = pathinfo($exp[0]);

			$filename = 'displaySrc.'.$pathinfo['extension'];
			$downloadURLs[$arrayI]['url'] = $postInfo['displaySrc'];
			$downloadURLs[$arrayI]['saveas'] = $filename;

			if(trim($postInfo['videoUrl']) != ''){
				$arrayI++;
				$basename = basename($postInfo['videoUrl']);
				$exp = explode('?',$basename);
				$pathinfo = pathinfo($exp[0]);

				$filename = 'video.'.$pathinfo['extension'];
				$downloadURLs[$arrayI]['url'] = $postInfo['videoUrl'];
				$downloadURLs[$arrayI]['saveas'] = $filename;
			}

			foreach($downloadURLs as $key => $value){
				$savepath = $save_folder.$value['saveas'];

				if (!file_exists($savepath)) {
					if(downloadFile($value['url'], $savepath)) {
						$logger->info($value['saveas']." - file downloaded successfully to ".$save_folder);
						$optimizerChain = OptimizerChainFactory::create();
						$optimizerChain->optimize($savepath);
					} else {
						$logger->info($value['saveas']." - file downloading failed to ".$save_folder);
					}
				}
			}
		}

		// avoid 429 Rate limit from Instagram
		sleep(2);
	} while ($profile->hasMoreMedias());

	if(isset($instagramArray) && count($instagramArray) != 0){
		array_sort_by_column($instagramArray, 'createdTime-ts', SORT_DESC);
		$instagramJSON = json_encode($instagramArray);
		file_put_contents($cacheFolder.'jsonifiedInstagramPosts.json', $instagramJSON);
	}

} catch (InstagramException $e) {
	$logger->debug("InstagramException");
	print_r($e->getMessage());
} catch (CacheException $e) {
	$logger->debug("CacheException");
	print_r($e->getMessage());
}
