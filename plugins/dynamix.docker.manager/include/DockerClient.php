<?PHP
/* Copyright 2015, Lime Technology
 * Copyright 2015, Guilherme Jardim, Eric Schultz, Jon Panozzo.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 */
?>
<?

$dockerManPaths = [
	'plugin'            => '/usr/local/emhttp/plugins/dynamix.docker.manager',
	'autostart-file'    => '/var/lib/docker/unraid-autostart',
	'template-repos'    => '/boot/config/plugins/dockerMan/template-repos',
	'templates-user'    => '/boot/config/plugins/dockerMan/templates-user',
	'templates-storage' => '/boot/config/plugins/dockerMan/templates',
	'images-ram'        => '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images',
	'images-storage'    => '/boot/config/plugins/dockerMan/images',
	'webui-info'        => '/usr/local/emhttp/state/plugins/dynamix.docker.manager/docker.json',
	'update-status'     => '/var/lib/docker/unraid-update-status.json'
];

#load emhttp variables if needed.
if (!isset($var)) {
	if (!is_file("/usr/local/emhttp/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(lsof -nPc emhttp | grep -Po 'TCP[^\d]*\K\d+')");
	$var = @parse_ini_file("/usr/local/emhttp/state/var.ini");
}

$docker_cfg_defaults = [
	"DOCKER_ENABLED" => "no",
	"DOCKER_OPTS" => "--storage-driver=btrfs",
	"DOCKER_IMAGE_SIZE" => "20",
	"DOCKER_IMAGE_FILE" => "/mnt/user/system/docker/docker.img",
	"DOCKER_APP_CONFIG_PATH" => "/mnt/user/appdata/",
	"DOCKER_APP_UNRAID_PATH" => ""
];
$dockercfg = $docker_cfg_defaults;

// Docker configuration file - create if needed
$docker_cfgfile = "/boot/config/docker.cfg";
if (!file_exists($docker_cfgfile)) {
	$tmp = '';
	foreach ($docker_cfg_defaults as $key => $value) $tmp .= "$key=\"$value\"\n";
	file_put_contents($docker_cfgfile, $tmp);
} else {
	$docker_cfg_existing = parse_ini_file($docker_cfgfile);
	if (!empty($docker_cfg_existing)) {
		$dockercfg = array_merge($docker_cfg_defaults, $docker_cfg_existing);
	}
}

######################################
##   	DOCKERTEMPLATES CLASS       ##
######################################

class DockerTemplates {

	public $verbose = false;

	private function debug($m) {
		if ($this->verbose) echo $m."\n";
	}

	public function download_url($url, $path = "", $bg = false) {
		exec("curl --max-time 60 --silent --insecure --location --fail ".($path ? " -o ".escapeshellarg($path) : "")." ".escapeshellarg($url)." ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code);
		return ($exit_code === 0) ? implode("\n", $out) : false;
	}

	public function listDir($root, $ext = null) {
		$iter = new RecursiveIteratorIterator(
						new RecursiveDirectoryIterator($root,
						RecursiveDirectoryIterator::SKIP_DOTS),
						RecursiveIteratorIterator::SELF_FIRST,
						RecursiveIteratorIterator::CATCH_GET_CHILD);
		$paths = [];
		foreach ($iter as $path => $fileinfo) {
			$fext = $fileinfo->getExtension();
			if ($ext && ($ext != $fext)) continue;
			if ($fileinfo->isFile()) $paths[] = ['path' => $path, 'prefix' => basename(dirname($path)), 'name' => $fileinfo->getBasename(".$fext")];
		}
		return $paths;
	}


	public function getTemplates($type) {
		global $dockerManPaths;
		$tmpls = [];
		$dirs = [];
		if ($type == "all") {
			$dirs[] = $dockerManPaths['templates-user'];
			$dirs[] = $dockerManPaths['templates-storage'];
		} else if ($type == "user") {
			$dirs[] = $dockerManPaths['templates-user'];
		} else if ($type == "default") {
			$dirs[] = $dockerManPaths['templates-storage'];
		} else {
			$dirs[] = $type;
		}
		foreach ($dirs as $dir) {
			if (!is_dir($dir)) @mkdir($dir, 0770, true);
			$tmpls = array_merge($tmpls, $this->listDir($dir, "xml"));
		}
		return $tmpls;
	}


	private function removeDir($path) {
		if (is_dir($path)) {
			$files = array_diff(scandir($path), ['.', '..']);
			foreach ($files as $file) {
				$this->removeDir(realpath($path) . '/' . $file);
			}
			return rmdir($path);
		} else if (is_file($path)) {
			return unlink($path);
		}
		return false;
	}


	public function downloadTemplates($Dest = null, $Urls = null) {
		global $dockerManPaths;
		$Dest = ($Dest) ? $Dest : $dockerManPaths['templates-storage'];
		$Urls = ($Urls) ? $Urls : $dockerManPaths['template-repos'];
		$repotemplates = [];
		$output = "";
		$tmp_dir = "/tmp/tmp-".mt_rand();
		if (!file_exists($dockerManPaths['template-repos'])) {
			@mkdir(dirname($dockerManPaths['template-repos']), 0777, true);
			@file_put_contents($dockerManPaths['template-repos'], "https://github.com/limetech/docker-templates");
		}
		$urls = @file($Urls, FILE_IGNORE_NEW_LINES);
		if (!is_array($urls)) return false;
		$this->debug("\nURLs:\n   " . implode("\n   ", $urls));
		$api_regexes = [
			'%/.*github.com/([^/]*)/([^/]*)/tree/([^/]*)/(.*)$%i',
			'%/.*github.com/([^/]*)/([^/]*)/tree/([^/]*)$%i',
			'%/.*github.com/([^/]*)/(.*).git%i',
			'%/.*github.com/([^/]*)/(.*)%i'
		];
		foreach ($urls as $url) {
			foreach ($api_regexes as $api_regex) {
				if (preg_match($api_regex, $url, $matches)) {
					$github_api['user']   = (isset($matches[1])) ? $matches[1] : "";
					$github_api['repo']   = (isset($matches[2])) ? $matches[2] : "";
					$github_api['branch'] = (isset($matches[3])) ? $matches[3] : "master";
					$github_api['path']   = (isset($matches[4])) ? $matches[4] : "";
					$github_api['url']    = sprintf("https://github.com/%s/%s/archive/%s.tar.gz", $github_api['user'], $github_api['repo'], $github_api['branch']);
					break;
				}
			}
			if ($this->download_url($github_api['url'], "$tmp_dir.tar.gz") === false) {
				$this->debug("\n Download ".$github_api['url']." has failed.");
				return null;
			} else {
				@mkdir($tmp_dir, 0777, true);
				shell_exec("tar -zxf $tmp_dir.tar.gz --strip=1 -C $tmp_dir/ 2>&1");
				unlink("$tmp_dir.tar.gz");
			}
			$tmplsStor = [];
			$this->debug("\n Templates found in ".$github_api['url']);
			foreach ($this->getTemplates($tmp_dir) as $template) {
				$storPath = sprintf("%s/%s", $Dest, str_replace($tmp_dir."/", "", $template['path']));
				$tmplsStor[] = $storPath;
				if (!is_dir(dirname($storPath))) @mkdir(dirname($storPath), 0777, true);
				if (is_file($storPath)) {
					if (sha1_file($template['path']) === sha1_file($storPath)) {
						$this->debug("   Skipped: ".$template['prefix'].'/'.$template['name']);
						continue;
					} else {
						@copy($template['path'], $storPath);
						$this->debug("   Updated: ".$template['prefix'].'/'.$template['name']);
					}
				} else {
					@copy($template['path'], $storPath);
					$this->debug("   Added: ".$template['prefix'].'/'.$template['name']);
				}
			}
			$repotemplates = array_merge($repotemplates, $tmplsStor);
			$output[$url] = $tmplsStor;
			$this->removeDir($tmp_dir);
		}
		// Delete any templates not in the repos
		foreach ($this->listDir($Dest, "xml") as $arrLocalTemplate) {
			if (!in_array($arrLocalTemplate['path'], $repotemplates)) {
				unlink($arrLocalTemplate['path']);
				$this->debug("   Removed: ".$arrLocalTemplate['prefix'].'/'.$arrLocalTemplate['name']."\n");
				// Any other files left in this template folder? if not delete the folder too
				$files = array_diff(scandir(dirname($arrLocalTemplate['path'])), ['.', '..']);
				if (empty($files)) {
					rmdir(dirname($arrLocalTemplate['path']));
					$this->debug("   Removed: ".$arrLocalTemplate['prefix']);
				}
			}
		}
		return $output;
	}


	public function getTemplateValue($Repository, $field, $scope = "all") {
		foreach ($this->getTemplates($scope) as $file) {
			$doc = new DOMDocument();
			$doc->load($file['path']);
			$TemplateRepository = $doc->getElementsByTagName("Repository")->item(0)->nodeValue;
			if (!preg_match("/:[\w]*$/i", $TemplateRepository)) {
				$Repo = preg_replace("/:[\w]*$/i", "", $Repository);
			} else {
				$Repo = $Repository;
			}

			if ($Repo == $TemplateRepository) {
				$TemplateField = $doc->getElementsByTagName($field)->item(0)->nodeValue;
				return trim($TemplateField);
			}
		}
		return null;
	}


	public function getUserTemplate($Container) {
		foreach ($this->getTemplates("user") as $file) {
			$doc = new DOMDocument('1.0', 'utf-8');
			$doc->load($file['path']);
			$Name = $doc->getElementsByTagName("Name")->item(0)->nodeValue;
			if ($Name == $Container) {
				return $file['path'];
			}
		}
		return false;
	}


	public function getControlURL($name) {
		global $var;
		$DockerClient = new DockerClient();

		$Repository = "";
		foreach ($DockerClient->getDockerContainers() as $ct) {
			if ($ct['Name'] == $name) {
				$Repository = preg_replace("/:[\w]*$/i", "", $ct['Image']);
				$Ports = $ct["Ports"];
				break;
			}
		}

		$WebUI = $this->getTemplateValue($Repository, "WebUI");

		if (preg_match("%\[IP\]%", $WebUI)) {
			$WebUI = preg_replace("%\[IP\]%", $var["IPADDR"], $WebUI);
			preg_match("%\[PORT:(\d+)\]%", $WebUI, $matches);
			$ConfigPort = $matches[1];
			if ($ct["NetworkMode"] == "bridge") {
				foreach ($Ports as $key) {
					if ($key["PrivatePort"] == $ConfigPort) {
						$ConfigPort = $key["PublicPort"];
					}
				}
			}
			$WebUI = preg_replace("%\[PORT:\d+\]%", $ConfigPort, $WebUI);
		}
		return $WebUI;
	}


	public function removeInfo($container, $image) {
		global $dockerManPaths;
		$image = ($image && count(preg_split("#[:\/]#", $image)) < 3) ? "${image}:latest" : $image;
		$dockerIni = $dockerManPaths['webui-info'];
		if (!is_dir(dirname($dockerIni))) @mkdir(dirname($dockerIni), 0770, true);
		$info = (is_file($dockerIni)) ? json_decode(file_get_contents($dockerIni), true) : [];
		if (!count($info)) $info = [];

		if (isset($info[$container])) unset($info[$container]);
		file_put_contents($dockerIni, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		$update_file = $dockerManPaths['update-status'];
		$updateStatus = (is_file($update_file)) ? json_decode(file_get_contents($update_file), true) : [];
		if (isset($updateStatus[$image])) unset($updateStatus[$image]);
		file_put_contents($update_file, json_encode($updateStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}


	public function getAllInfo($reload = false) {
		global $dockerManPaths;
		$DockerClient = new DockerClient();
		$DockerUpdate = new DockerUpdate();
		$DockerUpdate->verbose = $this->verbose;
		$new_info = [];

		$dockerIni = $dockerManPaths['webui-info'];
		if (!is_dir(dirname($dockerIni))) @mkdir(dirname($dockerIni), 0770, true);
		$info = (is_file($dockerIni)) ? json_decode(file_get_contents($dockerIni), true) : [];
		if (!count($info)) $info = [];

		$autostart_file = $dockerManPaths['autostart-file'];
		$allAutoStart = @file($autostart_file, FILE_IGNORE_NEW_LINES);
		if ($allAutoStart === false) $allAutoStart = [];

		foreach ($DockerClient->getDockerContainers() as $ct) {
			$name           = $ct['Name'];
			$image          = $ct['Image'];
			$tmp            = (count($info[$name])) ? $info[$name] : [];

			$tmp['running'] = $ct['Running'];
			$tmp['autostart'] = in_array($name, $allAutoStart);

			if (!$tmp['icon'] || !$tmp['banner'] || $reload) {
				$img = $this->getBannerIcon($image);
				$tmp['banner'] = ($img['banner']) ? $img['banner'] : null;
				$tmp['icon']   = ($img['icon'])   ? $img['icon'] : null;
			}
			if (!$tmp['url'] || $reload) {
				$WebUI      = $this->getControlURL($name);
				$tmp['url'] = ($WebUI) ? $WebUI : null;
			}

			$Registry = $this->getTemplateValue($image, "Registry");
			$tmp['registry'] = ($Registry) ? $Registry : null;

			if (!$tmp['updated'] || $reload) {
				if ($reload) $DockerUpdate->reloadUpdateStatus($image);
				$vs = $DockerUpdate->getUpdateStatus($image);
				$tmp['updated'] = ($vs === null) ? null : ($vs === true) ? 'true' : 'false';
			}

			if (!$tmp['template'] || $reload) {
				$tmp['template'] = $this->getUserTemplate($name);
			}

			$this->debug("\n$name");
			foreach ($tmp as $c => $d) $this->debug(sprintf("   %-10s: %s", $c, $d));
			$new_info[$name] = $tmp;
		}
		file_put_contents($dockerIni, json_encode($new_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		return $new_info;
	}


	public function getBannerIcon($Repository) {
		global $dockerManPaths;
		$out = [];

		$Images = [
			'banner' => $this->getTemplateValue($Repository, "Banner"),
			'icon' => $this->getTemplateValue($Repository, "Icon")
		];

		foreach ($Images as $type => $imgUrl) {
			preg_match_all("/(.*?):([\w]*$)/i", $Repository, $matches);
			$tempPath    = sprintf("%s/%s-%s-%s.png", $dockerManPaths['images-ram'], preg_replace('%\/|\\\%', '-', $matches[1][0]), $matches[2][0], $type);
			$storagePath = sprintf("%s/%s-%s-%s.png", $dockerManPaths['images-storage'], preg_replace('%\/|\\\%', '-', $matches[1][0]), $matches[2][0], $type);
			if (!is_dir(dirname($tempPath))) @mkdir(dirname($tempPath), 0770, true);
			if (!is_dir(dirname($storagePath))) @mkdir(dirname($storagePath), 0770, true);
			if (!is_file($tempPath)) {
				if (!is_file($storagePath)) {
					$this->download_url($imgUrl, $storagePath);
				}
				@copy($storagePath, $tempPath);
			}
			$out[$type] = (is_file($tempPath)) ? str_replace('/usr/local/emhttp', '', $tempPath) : "";
		}
		return $out;
	}
}


######################################
##   	  DOCKERUPDATE CLASS        ##
######################################
class DockerUpdate{
	public $verbose = false;

	private function debug($m) {
		if ($this->verbose) echo $m."\n";
	}


	public function download_url($url, $path = "", $bg = false) {
		exec("curl --max-time 30 --silent --insecure --location --fail ".($path ? " -o ".escapeshellarg($path) : "")." ".escapeshellarg($url)." ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code);
		return ($exit_code === 0) ? implode("\n", $out) : false;
	}


	public function download_url_and_headers($url, $headers = "", $path = "", $bg = false) {
		exec("curl --max-time 30 --silent --insecure --location --fail -i ".($headers ? " -H ".escapeshellarg($headers) : "").($path ? " -o ".escapeshellarg($path) : "")." ".escapeshellarg($url)." ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code);
		return ($exit_code === 0) ? implode("\n", $out) : false;
	}


	// DEPRECATED: Only used for Docker Index V1 type update checks
	public function getRemoteVersion($image) {
		$apiUrl = vsprintf("http://index.docker.io/v1/repositories/%s/%s/tags/%s", preg_split("#[:\/]#", $image));
		$this->debug("API URL: $apiUrl");
		$apiContent = $this->download_url($apiUrl);
		return ($apiContent === false) ? null : substr(json_decode($apiContent, true)[0]['id'], 0, 8);
	}


	public function getRemoteVersionV2($image) {
		// First - get auth token:
		//   https://auth.docker.io/token?service=registry.docker.io&scope=repository:needo/nzbget:pull
		$strAuthURL = vsprintf("https://auth.docker.io/token?service=registry.docker.io&scope=repository:%s:pull", strstr($image.':', ':', true));
		$this->debug("Auth URL: $strAuthURL");
		$arrAuth = json_decode($this->download_url($strAuthURL), true);
		if (empty($arrAuth) || empty($arrAuth['token'])) {
			$this->debug("Error: Auth Token was missing/empty");
			return null;
		}
		$this->debug("Auth Token: ".$arrAuth['token']);

		// Next - get manifest:
		//   curl -H "Authorization: Bearer <TOKEN>" https://registry-1.docker.io/v2/needo/nzbget/manifests/latest
		$strManifestURL = vsprintf("https://registry-1.docker.io/v2/%s/%s/manifests/%s", preg_split("#[:\/]#", $image));
		$this->debug("Manifest URL: $strManifestURL");
		$strManifest = $this->download_url_and_headers($strManifestURL, "Authorization: Bearer ".$arrAuth['token']);
		if (empty($strManifest)) {
			$this->debug("Error: Manifest response was empty");
			return null;
		}

		// Look for 'Docker-Content-Digest' header in response:
		//   Docker-Content-Digest: sha256:2070d781fc5f98f12e752b75cf39d03b7a24b9d298718b1bbb73e67f0443062d
		$strDigest = '';
		foreach (preg_split('/\r\n|\r|\n/', $strManifest) as $strLine) {
			if (strpos($strLine, 'Docker-Content-Digest: ') === 0) {
				$strDigest = substr($strLine, 23);
				break;
			}
		}
		if (empty($strDigest)) {
			$this->debug("Error: Remote Digest was missing/empty");
			return null;
		}

		$this->debug("Remote Digest: $strDigest");
		return $strDigest;
	}


	// DEPRECATED: Only used for Docker Index V1 type update checks
	public function getLocalVersion($image) {
		$DockerClient = new DockerClient();
		return substr($DockerClient->getImageID($image), 0, 8);
	}


	public function getUpdateStatus($image) {
		global $dockerManPaths;
		if (is_file($dockerManPaths['update-status'])) {
			$updateStatus = json_decode(file_get_contents($dockerManPaths['update-status']), true);
			// Add :latest tag to image if it's absent
			$image = ($image && count(preg_split("#[:\/]#", $image)) < 3) ? "${image}:latest" : $image;
			if (isset($updateStatus[$image])) {
				if ($updateStatus[$image]['local'] || $updateStatus[$image]['remote']) {
					return ($updateStatus[$image]['local'] == $updateStatus[$image]['remote']) ? true : false;
				}
			}
		}
		return null;
	}


	public function reloadUpdateStatus($image = null) {
		global $dockerManPaths;
		$DockerClient = new DockerClient();
		$update_file  = $dockerManPaths['update-status'];
		$updateStatus = (is_file($update_file)) ? json_decode(file_get_contents($update_file), true) : [];

		// Add :latest tag to image if it's absent
		$image = ($image && count(preg_split("#[:\/]#", $image)) < 3) ? "${image}:latest" : $image;
		$images = ($image) ? [$image] : array_map(function($ar){return $ar['Tags'][0];}, $DockerClient->getDockerImages());
		foreach ($images as $img) {
			$localVersion = null;
			if (!empty($updateStatus[$img]) && array_key_exists('local', $updateStatus[$img])) {
				$localVersion = $updateStatus[$img]['local'];
			}
			$remoteVersion = $this->getRemoteVersionV2($img);
			$status        = ($localVersion && $remoteVersion) ? (($remoteVersion == $localVersion) ? "true" : "false") : "undef";
			$updateStatus[$img] = [
				'local'  => $localVersion,
				'remote' => $remoteVersion,
				'status' => $status
			];
			$this->debug("Update status: Image='${img}', Local='${localVersion}', Remote='${remoteVersion}'");
		}
		file_put_contents($update_file, json_encode($updateStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}


	public function setUpdateStatus($image, $version) {
		global $dockerManPaths;
		$update_file  = $dockerManPaths['update-status'];
		$updateStatus = (is_file($update_file)) ? json_decode(file_get_contents($update_file), true) : [];
		$updateStatus[$image] = [
			'local'  => $version,
			'remote' => $version,
			'status' => 'true'
		];
		$this->debug("Update status: Image='${image}', Local='${version}', Remote='${version}'");
		file_put_contents($update_file, json_encode($updateStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
	}
}


######################################
##   	  DOCKERCLIENT CLASS        ##
######################################
class DockerClient {

	private $allContainersCache = null;

	private $allImagesCache = null;


	private function build_sorter($key) {
		return function ($a, $b) use ($key) {
			return strnatcmp(strtolower($a[$key]), strtolower($b[$key]));
		};
	}


	public function humanTiming($time) {
		$time = time() - $time; // to get the time since that moment
		$tokens = [
			31536000 => 'year',
			2592000  => 'month',
			604800   => 'week',
			86400    => 'day',
			3600     => 'hour',
			60       => 'minute',
			1        => 'second'
		];
		foreach ($tokens as $unit => $text) {
			if ($time < $unit) continue;
			$numberOfUnits = floor($time / $unit);
			return $numberOfUnits.' '.$text.(($numberOfUnits>1)?'s':'').' ago';
		}
	}


	public function formatBytes($size) {
		if ($size == 0) return '0 B';
		$base = log($size) / log(1024);
		$suffix = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
		return round(pow(1024, $base - floor($base)), 0) .' '. $suffix[floor($base)];
	}


	public function getDockerJSON($url, $method = "GET", &$code = null, $callback = null, $unchunk = false) {
		$fp = stream_socket_client('unix:///var/run/docker.sock', $errno, $errstr);

		if ($fp === false) {
			echo "Couldn't create socket: [$errno] $errstr";
			return null;
		}
		$protocol = ($unchunk) ? "HTTP/1.0" : "HTTP/1.1";
		$out="${method} {$url} ${protocol}\r\nConnection: Close\r\n\r\n";
		fwrite($fp, $out);
		// Strip headers out
		$headers = '';
		while (($line = fgets($fp)) !== false) {
			if (strpos($line, "HTTP/1") !== false) {
				$code = vsprintf('%2$s',preg_split("#\s+#", $line));
			}
			$headers .= $line;
			if (rtrim($line) == '') {
				break;
			}
		}
		$data = [];
		while (($line = fgets($fp)) !== false) {
			if (is_array($j = json_decode($line, true))) {
				$data = array_merge($data, $j);
			}
			if ($callback) $callback($line);
		}
		fclose($fp);
		return $data;
	}


	function doesContainerExist($container) {
		foreach ($this->getDockerContainers() as $ct) {
			if ($ct['Name'] == $container) {
				return true;
			}
		}
		return false;
	}


	function doesImageExist($image) {
		foreach ($this->getDockerImages() as $img) {
			if (strpos($img['Tags'][0], $image) !== false) {
				return true;
			}
		}
		return false;
	}


	public function getInfo() {
		$info = $this->getDockerJSON("/info");
		$version = $this->getDockerJSON("/version");
		return array_merge($info, $version);
	}


	public function getContainerLog($id, $callback, $tail = null, $since = null) {
		$this->getDockerJSON("/containers/${id}/logs?stderr=1&stdout=1&tail=".urlencode($tail)."&since=".urlencode($since), "GET", $code, $callback, true);
	}


	public function getContainerDetails($id) {
		return $this->getDockerJSON("/containers/${id}/json");
	}


	public function startContainer($id) {
		$this->getDockerJSON("/containers/${id}/start", "POST", $code);
		$this->allContainersCache = null; // flush cache
		$codes = [
			"204" => true, // No error
			"304" => "Container already started",
			"404" => "No such container",
			"500" => "Server error"
		];
		return (array_key_exists($code, $codes)) ? $codes[$code] : 'Error code '.$code;
	}


	public function stopContainer($id) {
		$this->getDockerJSON("/containers/${id}/stop", "POST", $code);
		$this->allContainersCache = null; // flush cache
		$codes = [
			"204" => true, // No error
			"304" => "Container already stopped",
			"404" => "No such container",
			"500" => "Server error"
		];
		return (array_key_exists($code, $codes)) ? $codes[$code] : 'Error code '.$code;
	}


	public function restartContainer($id) {
		$this->getDockerJSON("/containers/${id}/restart", "POST", $code);
		$this->allContainersCache = null; // flush cache
		$codes = [
			"204" => true, // No error
			"404" => "No such container",
			"500" => "Server error"
		];
		return (array_key_exists($code, $codes)) ? $codes[$code] : 'Error code '.$code;
	}


	public function removeContainer($id) {
		$this->getDockerJSON("/containers/${id}?force=1", "DELETE", $code);
		$this->allContainersCache = null; // flush cache
		$codes = [
			"204" => true, // No error
			"400" => "Bad parameter",
			"404" => "No such container",
			"500" => "Server error"
		];
		return (array_key_exists($code, $codes)) ? $codes[$code] : 'Error code '.$code;
	}


	public function pullImage($image, $callback = null) {
		$ret = $this->getDockerJSON("/images/create?fromImage=".urlencode($image), "POST", $code, $callback);
		$this->allImagesCache = null; // flush cache
		return $ret;
	}


	public function removeImage($id) {
		$this->getDockerJSON("/images/${id}?force=1", "DELETE", $code);
		$this->allImagesCache = null; // flush cache
		$codes = [
			"200" => true, // No error
			"404" => "No such image",
			"409" => "Conflict: image used by container(s): ".implode(', ', $this->usedBy($id)),
			"500" => "Server error"
		];
		return (array_key_exists($code, $codes)) ? $codes[$code] : 'Error code '.$code;
	}


	private function getImageDetails($id) {
		return $this->getDockerJSON("/images/${id}/json");
	}


	public function getDockerContainers() {
		// Return cached values
		if (is_array($this->allContainersCache)) {
			return $this->allContainersCache;
		}

		$this->allContainersCache = [];
		foreach ($this->getDockerJSON("/containers/json?all=1") as $obj) {
			$details = $this->getContainerDetails($obj['Id']);

			// Docker 1.7 uses full image ID when there aren't tags, so lets crop it
			$Image = (strlen($obj['Image']) == 64) ? substr($obj['Image'], 0, 12) : $obj['Image'];

			// Docker 1.7 doesn't automatically append the tag 'latest', so we do that now if there's no tag
			if ($Image && count(preg_split("#[:\/]#", $Image)) < 3) {
				$Image .= ':latest';
			}

			$c = [];
			$c["Image"]       = $Image;
			$c["ImageId"]     = substr(str_replace('sha256:', '', $details["Image"]), 0, 12);
			$c["Name"]        = substr($details['Name'], 1);
			$c["Status"]      = $obj['Status'] ? $obj['Status'] : "None";
			$c["Running"]     = $details["State"]["Running"];
			$c["Cmd"]         = $obj['Command'];
			$c["Id"]          = substr($obj['Id'], 0, 12);
			$c['Volumes']     = $details["HostConfig"]['Binds'];
			$c["Created"]     = $this->humanTiming($obj['Created']);
			$c["NetworkMode"] = $details['HostConfig']['NetworkMode'];
			$c["BaseImage"]   = isset($obj["Labels"]["BASEIMAGE"]) ? $obj["Labels"]["BASEIMAGE"] : false;
			$c["Ports"]       = [];

			if ($c["NetworkMode"] != 'host' && !empty($details['HostConfig']['PortBindings'])) {
				foreach ($details['HostConfig']['PortBindings'] as $port => $value) {
					list($PrivatePort, $Type) = explode("/", $port);
					$c["Ports"][] = [
						'IP'          => empty($value[0]['HostIP']) ? '0.0.0.0' : $value[0]['HostIP'],
						'PrivatePort' => $PrivatePort,
						'PublicPort'  => $value[0]['HostPort'],
						'Type'        => $Type
					];
				}
			}

			$this->allContainersCache[] = $c;
		}
		usort($this->allContainersCache, $this->build_sorter('Name'));
		return $this->allContainersCache;
	}


	public function getContainerID($Container) {
		foreach ($this->getDockerContainers() as $ct) {
			preg_match("%" . preg_quote($Container, "%") ."%", $ct["Name"], $matches);
			if ($matches) {
				return $ct["Id"];
			}
		}
		return null;
	}


	public function getImageID($Image) {
		foreach ($this->getDockerImages() as $img) {
			preg_match("%" . preg_quote($Image, "%") ."%", $img["Tags"][0], $matches);
			if ($matches) {
				return $img["Id"];
			}
		}
		return null;
	}


	private function usedBy($imageId) {
		$out = [];
		foreach ($this->getDockerContainers() as $ct) {
			if ($ct["ImageId"] == $imageId) {
				$out[] = $ct["Name"];
			}
		}
		return $out;
	}


	public function getDockerImages() {
		// Return cached values
		if (is_array($this->allImagesCache)) {
			return $this->allImagesCache;
		}

		$this->allImagesCache = [];
		foreach ($this->getDockerJSON("/images/json?all=0") as $obj) {
			$c = [];
			$c["Created"]      = $this->humanTiming($obj['Created']);
			$c["Id"]           = substr(str_replace('sha256:', '', $obj['Id']), 0, 12);
			$c["ParentId"]     = substr(str_replace('sha256:', '', $obj['ParentId']), 0, 12);
			$c["Size"]         = $this->formatBytes($obj['Size']);
			$c["VirtualSize"]  = $this->formatBytes($obj['VirtualSize']);
			$c["Tags"]         = array_map("htmlentities", $obj['RepoTags']);
			$c["Repository"]   = vsprintf('%1$s/%2$s', preg_split("#[:\/]#", $obj['RepoTags'][0]));
			$c["usedBy"]       = $this->usedBy($c["Id"]);

			$this->allImagesCache[$c["Id"]]  = $c;
		}
		return $this->allImagesCache;
	}


	public function flushCaches() {
		$this->allContainersCache = null;
		$this->allImagesCache = null;
	}
}
?>
