<?php
	include "config.php";

	date_default_timezone_set('UTC');

	$function = "undefined";
	if(isset($_GET['fn']))
		$function = $_GET['fn'];

	// Check user is logged in
	if($function != "login-challenge" && $function != "login-response")
		validate() or exit();

	// Execute requested function
	switch($function) {
		case "ls":
			ls($_GET['folder']);
			break;
		case "zip":
			zip($_GET['folder']);
			break;
		case "thumb":
			get("small", $_GET['img']);
			break;
		case "web":
			get("web", $_GET['img']);
			break;
		case "img":
			get("src", $_GET['img']);
			break;
		case "video":
			get("video", $_GET['img']);
			break;
		case "info":
			info($_GET['img']);
			break;
		case "update":
			update();
			break;
		case "session":
			echo("logged");
			break;
		case "login-challenge":
			challenge();
			break;
		case "login-response":
			login($_GET['response']);
			break;
		case "logout":
			logout();
			break;
		default:
			apiError("Bad command: $function");
	}

	function validate() {
		GLOBAL $gallery;

		// If not password protected return true
		if(strlen($gallery['password']) == 0)
			return true;

		$sql = connect();

		// get ticket from cookie
		if(!isset($_COOKIE['ticket']))
			return false;
		$ticket = $sql->escape_string($_COOKIE['ticket']);

		// validate ticket
		$result = $sql->query("SELECT * FROM ticket WHERE ticket='$ticket'") or die(my_error());
		if($result->num_rows == 1)
		{
			// update time
			$sql->query("UPDATE ticket SET time=CURRENT_TIMESTAMP WHERE ticket='$ticket'") or die(my_error());
			return true;
		}

		return false;
	}

	function challenge() {
		$sql = connect();

		// gen challenge data
		$challenge = $sql->escape_string(rand());
		$address = $_SERVER['REMOTE_ADDR'];

		// wipe old challenges for address
		$sql->query("DELETE FROM challenge WHERE address='$address'");

		// store challenge to database
		$sql->query("INSERT INTO challenge ( challenge, address ) VALUES ( '$challenge', '$address' )");

		echo $challenge;
	}

	function login($response) {
		GLOBAL $gallery;

		$sql = connect();

		$address = $_SERVER['REMOTE_ADDR'];

		// get salt from database
		$salt = "";
		$result = $sql->query("SELECT challenge FROM challenge WHERE address='$address'");
		if(($result) && ($result->num_rows > 0)) {
			$challenge = $result->fetch_assoc();
			$salt = $challenge['challenge'];
		} else return false;

		// remove all challenges for address
		$sql->query("DELETE FROM challenge WHERE address='$address'");

		$plaintext = $salt . $gallery['password'];
		$cyphertext = hash("sha512", $plaintext);

		// check response and add ticket
		if($response == $cyphertext) {
			// prepare ticket
			$ticket = rand();
			$user = 0;


			// add ticket to database and cookies
			$sql->query("INSERT INTO ticket ( ticket, user, address ) VALUES ( '$ticket', '$user', '$address' )") or die(my_error());
			setcookie('ticket', $ticket, time()+60*60*24*365*10);

			return true;
		} else {
			return false;
		}
	}

	function logout() {
		if(!isset($_COOKIE['ticket'])) {
			return;
		} else {
			$sql = connect();

			$ticket = $sql->escape_string($_COOKIE['ticket']);
			setcookie('ticket','');
			$sql->query("DELETE FROM ticket WHERE ticket='$ticket'");
		}
	}

	function connect() {
		GLOBAL $gallery;
		return new mysqli("p:" . $gallery['sql_host'], $gallery['sql_user'], $gallery['sql_pass'], $gallery['sql_db']);
	}

	function apiError($message) {
		echo "API error: $message";
		exit();
	}

	function isFileImage($name) {
		return preg_match('/\.[jJ][pP][eE]?[gG]$/', $name);
	}

	function isFileVideo($name) {
		return preg_match('/\.[mM][pP][4]$/', $name);
	}

	function listFolder($folder) {
		GLOBAL $gallery;

		// Check for ".." in folder name
		if(preg_match('/\.\./', $folder))
			apiError("Invalid chars in folder name: $folder");

		// Get folder content
		$path = $gallery['root'] . "/$folder";
		$items = array();
		if($handle = opendir($path)) {
			while(false !== ($entry = readdir($handle))) {
				if($entry != "." && $entry != ".." && !preg_match('/^\..*/', $entry)) {
					// Add new item
					$item = array();
					$item['name'] = $entry;
					if(is_dir("$path/$entry"))
						$item['type'] = "directory";
					else if(isFileImage($entry))
						$item['type'] = "image";
					else if(isFileVideo($entry))
						$item['type'] = "video";
					else continue;
					if($folder == "")
						$item['path'] = $entry;
					else
						$item['path'] = "$folder/$entry";

					$items[] = $item;
				}
			}
			closedir($handle);
		}

		return $items;
	}

	function ls($folder) {
		header('Content-type: application/json');
		echo(json_encode(listFolder($folder)));
	}

	function zip($folder) {
		GLOBAL $gallery;
		header('Content-type: application/zip');
		header("Content-Disposition: attachment; filename=" . urlencode($folder . ".zip"));
		chdir($gallery['root']);
		passthru("zip -0 -r - \"$folder\"");
		exit();
	}

	function api_makeSmall($image) {
		GLOBAL $gallery;
		$src = $gallery['root'] . "/" . $image;
		$hash = hash("sha1", "small/" . $image);
		$hashDir = $gallery['scaled'] . "/" . substr($hash, 0, 2);

		// Ensure hashdir
		if(!is_dir($hashDir))
			mkdir($hashDir);

		$small = $hashDir . "/" . $hash;

		// Ensure image
		if(!is_file($small) || filemtime($src) > filemtime($small)) {
			if(isFileImage($src))
				system('convert -thumbnail 128x128 "' . $src . '" "' . $small . '"');
			if(isFileVideo($src))
				system('ffmpegthumbnailer -s 128 -c jpg -i"' . $src . '" -o "' . $small . '"');

			touch($small);
		}

		return $small;
	}

	function api_makeWeb($image) {
		GLOBAL $gallery;
		$src = $gallery['root'] . "/" . $image;
		$hash = hash("sha1", "web/" . $image);
		$hashDir = $gallery['scaled'] . "/" . substr($hash, 0, 2);

		// Ensure hashdir
		if(!is_dir($hashDir))
			mkdir($hashDir);

		$web = $hashDir . "/" . $hash;

		// Ensure image
		if(!is_file($web) || filemtime($src) > filemtime($web)) {
			system('convert "' . $src . '" -resize "1024x768>" -compress JPEG -quality 80  "' . $web . '"');
			touch($web);
		}

		return $web;
	}

	function get($size, $image) {
		GLOBAL $gallery;

		$src = $gallery['root'] . "/" . $image;

		switch($size) {
			case "video":
			case "src":
				$img = $src;
				break;
			case "small":
				$img = api_makeSmall($image);
				break;
			case "web":
				$img = api_makeWeb($image);
				break;
			default:
				apiError("Image size: " . $size . " not uspported");
		}

/*		if($size == "video")
			header("Content-type: video/mp4");
		else
			header("Content-type: image/jpg");
	*/


	//	readfile($img);

		sendFile($img);
	}

	function info($image) {
		GLOBAL $gallery;

		$src = $gallery['root'] . "/" . $image;

		// Grather image information
		$exif = exif_read_data($src, 'EXIF');
		$info = array();

		if(isset($exif['DateTimeOriginal'])) $info['date'] = $exif['DateTimeOriginal'];
		if(isset($exif['ExifImageWidth']) && $exif['ExifImageLength']) $info['dimensions'] = $exif['ExifImageWidth'] . "x" . $exif['ExifImageLength'];
		$info['size'] = formatBytes(filesize($src));
		if(isset($exif['Make']) && isset($exif['Model'])) $info['model'] = $exif['Make'] . "(" . $exif['Model'] . ")";
		if(isset($exif['ExposureTime'])) $info['exposure'] = $exif['ExposureTime'];
		if(isset($exif['FNumber'])) $info['fnumber'] = $exif['FNumber'];
		if(isset($exif['ISOSpeedRatings'])) $info['iso'] = $exif['ISOSpeedRatings'];


		// Include EXIF comments if they are present in some form.
		$comments = array();
		if( isset( $exif['ImageDescription'] ) ) {
			$comments[] = $exif['ImageDescription'];
		}
		if( isset( $exif["COMMENT"] ) ) {
			foreach( $exif["COMMENT"] as $name => $value ) {
				if( !empty( $value ) ) {
					$comments[] = $value;
				}
			}
		}
		//if( isset( $exif["COMPUTED"]["UserComment"] ) ) {
		//	$comments[] = "User comment: " . $exif["COMPUTED"]["UserComment"];
		//}
		if( isset( $exif["EXIF"]["UserComment"] ) ) {
			$c = trim( $exif["EXIF"]["UserComment"] );
			if( !empty( $c ) ) {
				$comments[] = $exif["EXIF"]["UserComment"];
			}
		}
		if( !empty( $comments ) ) {
			$info["description"] = implode( "; ", $comments );
		}


		header('Content-type: application/json');
		echo(json_encode($info));
	}

	function update() {
		if (ob_get_level() == 0)
			ob_start();

		header('Content-Type: text/html; charset=utf-8');
		echo "Generating scaled images...</br>";

		function updateDir($path) {
			$items = listFolder($path);

			echo "Processing directory: $path</br>";
			echo str_pad('',4096)."\n";
			ob_flush();
			flush();

			foreach($items as $item) {
				if($item['type'] == "directory") {
					if($path == "")
						updateDir($item['name']);
					else
						updateDir($path . "/" . $item['name']);
				} else {
					echo "Processing file: " . $path . "/" . $item['name'] . "...";
					echo str_pad('',4096)."\n";
					ob_flush();
					flush();

					api_makeSmall($path . "/" . $item['name']);
					api_makeWeb($path . "/" . $item['name']);

					echo "done</br>";
					echo str_pad('',4096)."\n";
					ob_flush();
					flush();
				}
			}
		}

		updateDir("");

		ob_end_flush();
	}

	function formatBytes($bytes, $precision = 2) {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');

		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);

		$bytes /= (1 << (10 * $pow));

		return round($bytes, $precision) . ' ' . $units[$pow];
	}

	function sendFile($file) {
		$fp = @fopen($file, 'rb');

		if(!@fp) {
			header("HTTP/1.0 404 Not Found");
			exit();
		}

		header('Content-Type: ' . mime_content_type($file));
		header('Content-Disposition: attachment; filename="' . basename($file) . '"');

		$size   = filesize($file); // File size
		$length = $size;           // Content length
		$start  = 0;               // Start byte
		$end    = $size - 1;       // End byte

		header("Accept-Ranges: 0-$length");

		if (isset($_SERVER['HTTP_RANGE'])) {
			$c_start = $start;
			$c_end   = $end;
			// Extract the range string
			list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
			// Make sure the client hasn't sent us a multibyte range
			if (strpos($range, ',') !== false) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header("Content-Range: bytes $start-$end/$size");
				exit;
			}
			// If the range starts with an '-' we start from the beginning
			// If not, we forward the file pointer
			// And make sure to get the end byte if spesified
			if ($range{0} == '-') {
				// The n-number of the last bytes is requested
				$c_start = $size - substr($range, 1);
			} else {
				$range  = explode('-', $range);
				$c_start = $range[0];
				$c_end   = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size;
			}
			/* Check the range and make sure it's treated according to the specs.
			* http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html
			*/
			// End bytes can not be larger than $end.
			$c_end = ($c_end > $end) ? $end : $c_end;
			// Validate the requested range and return an error if it's not correct.
			if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
				header('HTTP/1.1 416 Requested Range Not Satisfiable');
				header("Content-Range: bytes $start-$end/$size");
				exit;
			}
			$start  = $c_start;
			$end    = $c_end;
			$length = $end - $start + 1; // Calculate new content length
			fseek($fp, $start);
			header('HTTP/1.1 206 Partial Content');
		}
		// Notify the client the byte range we'll be outputting
		header("Content-Range: bytes $start-$end/$size");
		header("Content-Length: $length");

		// Start buffered download
		$buffer = 1024 * 8;
		while(!feof($fp) && ($p = ftell($fp)) <= $end) {
			if ($p + $buffer > $end) {
				// In case we're only outputtin a chunk, make sure we don't
				// read past the length
				$buffer = $end - $p + 1;
			}
			set_time_limit(0); // Reset time limit for big files
			echo fread($fp, $buffer);
			flush(); // Free up memory. Otherwise large files will trigger PHP's memory limit.
		}

		fclose($fp);
	}
?>
