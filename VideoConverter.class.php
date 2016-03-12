<?php

	// Conversion Class
	class VideoConverter extends Config
	{
		// Private Fields
		private $_convertedFileName = '';
		private $_convertedFileType = '';
		private $_convertedFileCategory = '';
		private $_convertedFileQuality = parent::_DEFAULT_AUDIO_QUALITY;
		private $_convertedFileVolume = parent::_VOLUME;
		private $_vidSourceUrls = array();
		private $_tempVidFileName = '';
		private $_uniqueID;
		private $_percentVidDownloaded = 0;
		private $_currentVidHost = '';
		private $_vidInfo = array();
		private $_validationError = '';
		private $_skipConversion = false;
		private $_ffmpegCommand = '';
		private $_extractor;
		private $_curlResource;
		private $_outgoingIP = '';
		private $_doFFmpegCopy = false;

		// Constants
		const _FILENAME_DELIMITER = "~~";
		const _MAX_FILENAME_LENGTH = 255;

		#region Public Methods
		function __construct()
		{
			if (isset($_SESSION))
			{
				$this->_uniqueID = (!isset($_SESSION[parent::_SITENAME])) ? time() . "_" . uniqid('', true) : $_SESSION[parent::_SITENAME];
				$_SESSION[parent::_SITENAME] = (!isset($_SESSION[parent::_SITENAME])) ? $this->_uniqueID : $_SESSION[parent::_SITENAME];
				if (parent::_ENABLE_IP_ROTATION) Database::Connect(parent::_SERVER, parent::_DB_USER, parent::_DB_PASSWORD, parent::_DATABASE);
			}
			else
			{
				die('Error!: Session must be started in the calling file to use this class.');
			}
		}

		function __destruct()
		{
			if (parent::_ENABLE_IP_ROTATION) Database::Close();
		}

		function DownloadVideo($vidUrl)
		{
			$videoInfo = $this->GetVidInfo();
			if (!empty($videoInfo))
			{
				$this->SetConvertedFileName();
				$this->SetVidSourceUrls();
				if ($this->GetConvertedFileName() != '' && count($this->GetVidSourceUrls()) > 0)
				{
					$urls = $this->GetVidSourceUrls();
					if ((parent::_CACHING_ENABLED && !parent::_ENABLE_DIRECT_DOWNLOAD) || (parent::_ENABLE_DIRECT_DOWNLOAD && $this->GetConvertedFileCategory() != 'audio' && $this->GetConvertedFileVolume() == parent::_VOLUME))
					{
						$urls = $this->FilterUrls($urls);
					}
					return $this->SaveVideo($urls);
				}
			}
			return false;
		}

		function DoConversion()
		{
			$extractor = $this->GetExtractor();
			$vidHost = $this->GetCurrentVidHost();
			$fileType = $this->GetConvertedFileType();
			$fileCategory = $this->GetConvertedFileCategory();
			$fileQuality = $this->GetConvertedFileQuality();
			$fileVolume = $this->GetConvertedFileVolume();
			$newFile = $this->GetConvertedFileName();
			$tempFile = $this->GetTempVidFileName();
			if (!empty($fileType) && !empty($newFile) && !empty($tempFile))
			{
				$exec_string = '';
				$ftypes = $this->GetConvertedFileTypes();
				foreach ($ftypes as $ftype)
				{
					if ($fileType == $ftype['fileExt'])
					{
						$videoBitrate = parent::_DEFAULT_VIDEO_QUALITY;
						if ($fileCategory == 'video')
						{
							exec(parent::_FFMPEG.' -i '.$tempFile.' 2>&1 | grep "Video:\|bitrate:"', $output);
							if (count($output) > 0)
							{
								foreach ($output as $line)
								{
									if (preg_match('/(\d+)( kb\/s)/i', $line, $matches) == 1)
									{
										$videoBitrate = $matches[1];
										break;
									}
								}
							}
						}

						$ftypeFFmpeg = (isset($ftype['ffmpegCopy']) && $fileVolume == parent::_VOLUME && $fileQuality == parent::_DEFAULT_AUDIO_QUALITY && (($fileType == "aac" && $vidHost == "YouTube" && $extractor->AudioAvailable()) || $this->_doFFmpegCopy)) ? $ftype['ffmpegCopy'] : $ftype['ffmpeg'];
						$this->_ffmpegCommand = $exec_string = preg_replace(
							array('/%ffmpeg%/', '/%tempFile%/', '/%volume%/', '/%quality%/', '/%vquality%/', '/%newFile%/', '/%logsDir%/', '/%id%/'),
							array(parent::_FFMPEG, $tempFile, $fileVolume, $fileQuality, $videoBitrate, $newFile, parent::_LOGSDIR, $this->_uniqueID),
							$ftypeFFmpeg
						);
						break;
					}
				}
				//die($exec_string);
				$ffmpegExecUrl = preg_replace('/(([^\/]+?)(\.php))$/', "exec_ffmpeg.php", "http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']);
				$postData = "cmd=".urlencode($exec_string)."&token=".urlencode($this->_uniqueID);
				$strCookie = 'PHPSESSID=' . $_COOKIE['PHPSESSID'] . '; path=/';
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $ffmpegExecUrl);
				curl_setopt($ch, CURLOPT_POST, TRUE);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
				curl_setopt($ch, CURLOPT_TIMEOUT, 1);
				curl_setopt($ch, CURLOPT_COOKIE, $strCookie);
				curl_exec($ch);
				curl_close($ch);
			}
		}

		function DownloadConvertedFile($file, $directory)
		{
			$filepath = $directory . urldecode($file);
			$ftypes = $this->GetConvertedFileTypes();
			$mimeTypes = array();
			array_walk($ftypes, function($ftype) use(&$mimeTypes) {$mimeTypes[$ftype['fileExt']] = $ftype['mimeType'];});
			if ($this->ValidateDownloadFileName($filepath, $directory, array_keys($mimeTypes)))
			{
				$filename = $this->PrepareConvertedFileNameForDownload($file);
				$filepath = realpath($filepath);
				//if (filesize($filepath) > 10000) touch($filepath);
				$fileExt = trim(strrchr($filepath, '.'), '.');
				$contentType = ($fileExt == 'm4a') ? $mimeTypes['mp3'] : $mimeTypes[$fileExt];
				header('Content-Type: ' . $contentType);
				header('Content-Length: ' . filesize($filepath));
				header('Content-Disposition: attachment; filename="'.$filename.'"');
				ob_clean();
				flush();
				readfile($filepath);
				die();
			}
			else
			{
				$redirect = explode("?", $_SERVER['REQUEST_URI']);
				header('Location: ' . $redirect[0]);
			}
		}

		function ValidateConversionForm($vidUrl, $ftype, $getVidInfo=false, $moreOptions=array())
		{
			$vidHostName = $convertedFtype = '';
			$vidHosts = $this->GetVideoHosts();
			foreach ($vidHosts as $host)
			{
				foreach ($host['url_root'] as $urlRoot)
				{
					//$urlRoot = preg_replace('/^(([^\?]+?)(\?{1})(.+))$/', "$2$3", $urlRoot);
					$rootUrlPattern = preg_replace('/#wildcard#/', "[^\\\\/]+", preg_quote($urlRoot, '/'));
					$rootUrlPattern = ($host['allow_https_urls']) ? preg_replace('/^(http)/', "https?", $rootUrlPattern) : $rootUrlPattern;
					if (preg_match('/^('.$rootUrlPattern.')/i', $vidUrl) == 1)
					{
						$vidHostName = $host['name'];
						break 2;
					}
				}
			}
			$ftypes = $this->GetConvertedFileTypes();
			$convertedFtype = (in_array($ftype, array_keys($ftypes))) ? $ftypes[$ftype]['fileExt'] : '';
			$convertedFcategory = (in_array($ftype, array_keys($ftypes))) ? current(explode("/", $ftypes[$ftype]['mimeType'])) : '';
			$convertedFquality = (in_array($ftype, array_keys($ftypes)) && isset($ftypes[$ftype]['quality'])) ? $ftypes[$ftype]['quality'] : parent::_DEFAULT_AUDIO_QUALITY;
			$convertedFvolume = (isset($moreOptions['volume'])) ? abs((int)$moreOptions['volume']) : parent::_VOLUME;
			if (!empty($vidHostName) && !empty($convertedFtype) && !empty($convertedFcategory))
			{
				if ($vidHostName == 'SoundCloud' && $convertedFcategory == 'video')
				{
					$this->_validationError = 'Validation_Error_Audio_To_Video';
					return false;
				}
				$this->SetCurrentVidHost($vidHostName);
				$this->SetConvertedFileType($convertedFtype);
				$this->SetConvertedFileCategory($convertedFcategory);
				$this->SetConvertedFileQuality($convertedFquality);
				$this->SetConvertedFileVolume($convertedFvolume);
				$this->SetExtractor($vidHostName);
				if ($getVidInfo)
				{
					$extractor = $this->GetExtractor();
					$this->_vidInfo = $extractor->RetrieveVidInfo($vidUrl);
					if (isset($this->_vidInfo['is_video_audio']) && !$this->_vidInfo['is_video_audio'])
					{
						$this->_validationError = 'Validation_Error_General';
						return false;
					}
					$isOkDuration = true;
					foreach ($ftypes as $ftype)
					{
						if ($isOkDuration)
						{
							$quality = (isset($ftype['quality'])) ? $ftype['quality'] : parent::_DEFAULT_AUDIO_QUALITY;
							$isOkDuration = ($ftype['fileExt'] == $convertedFtype && $quality == $convertedFquality) ? (($ftype['maxDuration'] != -1) ? $ftype['maxDuration'] >= $this->_vidInfo['duration'] : true) : true;
						}
					}
					if (!$isOkDuration)
					{
						$this->_validationError = 'Validation_Error_Vid_Length';
						return false;
					}
				}
				return true;
			}
			$this->_validationError = 'Validation_Error_General';
			return false;
		}

		function PrepareConvertedFileNameForDownload($file)
		{
			$filename = urldecode($file);
			$filename = end(explode(self::_FILENAME_DELIMITER, $filename));
			$ftypesForRegex = $this->GetConvertedFileTypes();
			if (parent::_ENABLE_CONCURRENCY_CONTROL)
			{
				array_walk($ftypesForRegex, function(&$ftype, $key) {$ftype = $ftype['fileExt'];});
				$replacementStr = ((parent::_ENABLE_FILENAME_BRANDING) ? '[' . parent::_SITENAME . ']' : '') . "$4$5";
				$filename = preg_replace('/((_uuid-)(\w{13})(\.)('.implode('|', $ftypesForRegex).'))$/', $replacementStr, $filename);
			}
			$fileBasename = pathinfo($filename, PATHINFO_FILENAME);
			$filename = (empty($fileBasename) || (!parent::_ENABLE_UNICODE_SUPPORT && preg_match('/^([^a-zA-Z0-9]+)$/', $fileBasename) == 1)) ? 'unknown' . strrchr($filename, '.') : $filename;
			return $filename;
		}

		function ExtractVideoId($vidUrl)
		{
			$id = '';
			$url = trim($vidUrl);
			$urlQueryStr = parse_url($url, PHP_URL_QUERY);
			if ($urlQueryStr !== false && !empty($urlQueryStr))
			{
				$v = '';
				parse_str($urlQueryStr);
				if (!empty($v))
				{
					$id = $v;
				}
				else
				{
					$url = preg_replace('/(\?' . preg_quote($urlQueryStr, '/') . ')$/', "", $url);
					$id = trim(strrchr(trim($url, '/'), '/'), '/');
				}
			}
			else
			{
				$id = trim(strrchr(trim($url, '/'), '/'), '/');
			}
			return $id;
		}

		function RetrieveCachedFile()
		{
			$fileName = '';
			$videoInfo = $this->GetVidInfo();
			$ftype = $this->GetConvertedFileType();
			$fquality = $this->GetConvertedFileQuality();
			$fvolume = $this->GetConvertedFileVolume();
			$vidHosts = $this->GetVideoHosts();
			$vidHost = $this->GetCurrentVidHost();
			array_walk($vidHosts, function($host) use(&$videoInfo, $vidHost) {
				if ($host['name'] == $vidHost) $videoInfo['host_abbrev'] = $host['abbreviation'];
			});
			if ((!Config::_ENABLE_DIRECT_DOWNLOAD || (Config::_ENABLE_DIRECT_DOWNLOAD && $vidHost != "SoundCloud" && ($ftype == "mp3" || $ftype == "aac"))) && !empty($videoInfo) && !empty($videoInfo['title']) && !empty($videoInfo['id']) && isset($videoInfo['host_abbrev']))
			{
				$vTitle = html_entity_decode($videoInfo['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8');
				$fname = (!parent::_ENABLE_UNICODE_SUPPORT) ? preg_replace('/[^A-Za-z0-9 _-]/', '', $vTitle) : preg_replace('#/#', '', preg_replace('/\\|\/|\?|%|\*|:|\||"|<|>|\]|\[|\(|\)|\.|&|\^|\$|#|@|\!|`|~|=|\+|,|;|\'|\{|\}/', '', $vTitle));
				$fname = preg_replace('/_{2,}/', '_', preg_replace('/ /', '_', $fname));
				$dirName = parent::_CONVERTED_FILEDIR . $videoInfo['host_abbrev'] . '/' . $videoInfo['id'] . '/';
				if (is_dir(realpath($dirName)))
				{
					$filesystemIterator = new FilesystemIterator(realpath($dirName), FilesystemIterator::KEY_AS_FILENAME);
					$regexIterator = new RegexIterator($filesystemIterator, '/^(('.preg_quote($fquality . self::_FILENAME_DELIMITER . $fvolume . self::_FILENAME_DELIMITER . $fname, '/').')((_uuid-)(\w+))?(\.)('.preg_quote($ftype, '/').'))$/', RegexIterator::MATCH, RegexIterator::USE_KEY);
					$files = array_keys(iterator_to_array($regexIterator));
					$fileName = (!empty($files)) ? $dirName . $files[0] : '';
				}
			}
			//die($fileName);
			return $fileName;
		}
		#endregion

		#region Private "Helper" Methods
		private function ValidateDownloadFileName($filepath, $directory, array $fileExts)
		{
			$isValid = false;
			$fullFilepath = realpath($filepath);
			if ($fullFilepath !== false && $fullFilepath != $filepath && is_file($fullFilepath))
			{
				$normalizedAppRoot = (parent::_APPROOT != "/") ? preg_replace('/\//', DIRECTORY_SEPARATOR, parent::_APPROOT) : DIRECTORY_SEPARATOR;
				$pathBase = realpath($_SERVER['DOCUMENT_ROOT']) . $normalizedAppRoot;
				$safePath = preg_replace('/^(' . preg_quote($pathBase, '/') . ')/', "", $fullFilepath);
				if ($safePath != $fullFilepath && preg_match('/^(' . preg_quote(preg_replace('/\//', DIRECTORY_SEPARATOR, $directory), '/') . ')/', $safePath) == 1)
				{
					$fileExt = pathinfo($fullFilepath, PATHINFO_EXTENSION);
					$isValid = in_array($fileExt, $fileExts);
				}
			}
			return $isValid;
		}

		private function UpdateVideoDownloadProgress($curlResource, $downloadSize, $downloaded, $uploadSize, $uploaded)
		{
			$httpCode = curl_getinfo($curlResource, CURLINFO_HTTP_CODE);
			if ($httpCode == "200")
			{
				$percent = @round($downloaded/$downloadSize, 2) * 100;
				if ($percent > $this->_percentVidDownloaded)
				{
					$this->_percentVidDownloaded++;
					echo '<script type="text/javascript">updateVideoDownloadProgress("'. $percent .'");</script>';
					ob_end_flush();
					ob_flush();
					flush();
				}
			}
		}

		// Deprecated - May be removed in future versions!
		private function LegacyUpdateVideoDownloadProgress($downloadSize, $downloaded, $uploadSize, $uploaded)
		{
			$this->UpdateVideoDownloadProgress($this->_curlResource, $downloadSize, $downloaded, $uploadSize, $uploaded);
		}

		private function FilterUrls(array $urls)
		{
			$filteredUrls = array();
			$ftype = $this->GetConvertedFileType();
			$vidHosts = array_values($this->GetVideoHosts());
			$vidQualities = array_keys($vidHosts[0]['video_qualities']);
			$ftypes = $this->GetConvertedFileTypes();
			$uniqueFtypes = array();
			array_walk($ftypes, function($ft, $key) use(&$uniqueFtypes) {if (!isset($uniqueFtypes[$ft['fileExt']]) && isset($ft['qualityTolerance'])) $uniqueFtypes[$ft['fileExt']] = $ft['qualityTolerance'];});
			if (parent::_ENABLE_DIRECT_DOWNLOAD && isset($uniqueFtypes[$ftype]))
			{
				$availableQualityIndexes = array();
				array_walk($urls, function($url) use(&$availableQualityIndexes, $vidQualities) {if (in_array($url[1], $vidQualities)) $availableQualityIndexes[] = array_search($url[1], $vidQualities);});
				$ftypeQualityIndex = array_search($uniqueFtypes[$ftype], $vidQualities);
				$reduceQualityToleranceFurther = false;
				do
				{
					$filteredAvailableQuals = array_filter($availableQualityIndexes, function($index) use($ftypeQualityIndex) {return $index <= $ftypeQualityIndex;});
					if (empty($filteredAvailableQuals))
					{
						$uniqueFtypes[$ftype] = $vidQualities[++$ftypeQualityIndex];
						$reduceQualityToleranceFurther = $ftypeQualityIndex < count($vidQualities) - 1;
					}
					else
					{
						$reduceQualityToleranceFurther = false;
					}
				}
				while ($reduceQualityToleranceFurther);
			}
			foreach ($urls as $url)
			{
				$qualityToleranceCondition = (parent::_ENABLE_DIRECT_DOWNLOAD) ? array_search($url[1], $vidQualities) <= array_search($uniqueFtypes[$ftype], $vidQualities) : true;
				if ($ftype == $url[0] && in_array($url[1], $vidQualities) && $qualityToleranceCondition)
				{
					$filteredUrls[] = $url;
				}
			}
			return $filteredUrls;
		}

		private function SaveVideo(array $urls)
		{
			//die(print_r($urls));
			//die(print_r($this->GetVidSourceUrls()));
			$vidInfo = $this->GetVidInfo();
			$extractor = $this->GetExtractor();
			$this->_skipConversion = $skipConversion = parent::_ENABLE_DIRECT_DOWNLOAD && ($this->GetConvertedFileCategory() != 'audio' || ($this->GetCurrentVidHost() == 'SoundCloud' && !$vidInfo['downloadable'] && $this->GetConvertedFileType() == 'mp3' && $this->GetConvertedFileQuality() == '128') || ($this->GetCurrentVidHost() == 'YouTube' && $extractor->AudioAvailable() && $this->GetConvertedFileType() == 'm4a')) && $this->GetConvertedFileVolume() == parent::_VOLUME && !empty($urls);
			$this->_doFFmpegCopy = $doFFmpegCopy = parent::_CACHING_ENABLED && !parent::_ENABLE_DIRECT_DOWNLOAD && !empty($urls);
			if (!$skipConversion && !$doFFmpegCopy) $urls = $this->GetVidSourceUrls();
			$success = false;
			$vidCount = -1;
			while (!$success && ++$vidCount < count($urls))
			{
				$this->_percentVidDownloaded = 0;
				if (isset($urls[$vidCount-1]) && $urls[$vidCount-1][1] == 'au' && $skipConversion && !$forceBreak && $this->GetCurrentVidHost() == 'YouTube' && $this->GetConvertedFileType() == 'm4a')
				{
					$this->_skipConversion = $skipConversion = false;
				}
				if (!$skipConversion) $this->SetTempVidFileName();
				$filename = (!$skipConversion) ? $this->GetTempVidFileName() : $this->GetConvertedFileName();
				$tries = 0;
				$forceBreak = false;
				do
				{
					$file = fopen($filename, 'w');
					$progressFunction = (parent::_PHP_VERSION >= 5.5) ? 'UpdateVideoDownloadProgress' : 'LegacyUpdateVideoDownloadProgress';
					$this->_curlResource = $ch = curl_init();
					curl_setopt($ch, CURLOPT_FILE, $file);
					curl_setopt($ch, CURLOPT_HEADER, 0);
					curl_setopt($ch, CURLOPT_URL, end($urls[$vidCount]));
					curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
					if (parent::_ENABLE_IP_ROTATION && $this->GetCurrentVidHost() == "YouTube")
					{
						if ($this->GetOutgoingIP() == '' || $tries > 0) $this->SetOutgoingIP();
						curl_setopt($ch, CURLOPT_REFERER, '');
						curl_setopt($ch, CURLOPT_INTERFACE, $this->GetOutgoingIP());
						curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
						curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
					}
					curl_setopt($ch, CURLOPT_NOPROGRESS, false);
					curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, array($this, $progressFunction));
					curl_setopt($ch, CURLOPT_BUFFERSIZE, 4096000);
					curl_setopt($ch, CURLOPT_USERAGENT, parent::_REQUEST_USER_AGENT);
					curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
					curl_exec($ch);
					if (curl_errno($ch) == 0)
					{
						$curlInfo = curl_getinfo($ch);
						if (($this->GetCurrentVidHost() == "Dailymotion" || $this->GetCurrentVidHost() == "SoundCloud" || $this->GetCurrentVidHost() == "YouTube" || $this->GetCurrentVidHost() == "Pornhub") && $curlInfo['http_code'] == '302')
						{
							if (isset($curlInfo['redirect_url']) && !empty($curlInfo['redirect_url']))
							{
								$urls[$vidCount][2] = $curlInfo['redirect_url'];
								$vidCount--;
								$forceBreak = parent::_ENABLE_IP_ROTATION && $this->GetCurrentVidHost() == "YouTube";
							}
						}
						if (method_exists($extractor, 'GetCypherUsed') && $extractor->GetCypherUsed() && $curlInfo['http_code'] == '403')
						{
							$extractor->FixDecryption();
						}
					}
					curl_close($ch);
					fclose($file);
					if (is_file($filename))
					{
						if (!filesize($filename) || filesize($filename) < 10000)
						{
							unlink($filename);
						}
						else
						{
							$success = true;
						}
					}
					$tries++;
				}
				while (!$success && parent::_ENABLE_IP_ROTATION && $tries < parent::_MAX_CURL_TRIES && $this->GetCurrentVidHost() == "YouTube" && !$forceBreak);
			}
			return $success;
		}
		#endregion

		#region Properties
		public function GetConvertedFileName()
		{
			return $this->_convertedFileName;
		}
		private function SetConvertedFileName()
		{
			$videoInfo = $this->GetVidInfo();
			//die($videoInfo['title']);
			$ftype = $this->GetConvertedFileType();
			$fquality = $this->GetConvertedFileQuality();
			$fvolume = $this->GetConvertedFileVolume();
			$vidHosts = $this->GetVideoHosts();
			$vidHost = $this->GetCurrentVidHost();
			array_walk($vidHosts, function($host) use(&$videoInfo, $vidHost) {
				if ($host['name'] == $vidHost) $videoInfo['host_abbrev'] = $host['abbreviation'];
			});
			if (!empty($videoInfo) && !empty($videoInfo['title']) && !empty($videoInfo['id']) && isset($videoInfo['host_abbrev']) && !empty($ftype))
			{
				$vTitle = html_entity_decode($videoInfo['title'], ENT_COMPAT | ENT_HTML401, 'UTF-8');
				$fnameTitle = (!parent::_ENABLE_UNICODE_SUPPORT) ? preg_replace('/[^A-Za-z0-9 _-]/', '', $vTitle) : preg_replace('#/#', '', preg_replace('/\\|\/|\?|%|\*|:|\||"|<|>|\]|\[|\(|\)|\.|&|\^|\$|#|@|\!|`|~|=|\+|,|;|\'|\{|\}/', '', $vTitle));
				$fnameTitle = preg_replace('/_{2,}/', '_', preg_replace('/ /', '_', $fnameTitle));
				$fname = '';
				$excessFilenameLength = -1;
				do
				{
					$fnameTitle = ($excessFilenameLength >= 0) ? substr($fnameTitle, 0, strlen($fnameTitle) - $excessFilenameLength - 1) : $fnameTitle;
					$fname = $fquality . self::_FILENAME_DELIMITER . $fvolume . self::_FILENAME_DELIMITER . $fnameTitle;
					$fname .= (parent::_ENABLE_CONCURRENCY_CONTROL) ? uniqid('_uuid-') : '';
					$fname .= '.' . $ftype;
					$excessFilenameLength = strlen($fname) - self::_MAX_FILENAME_LENGTH;
				} while ($excessFilenameLength >= 0);  // If file name length is greater than or equal to _MAX_FILENAME_LENGTH bytes, truncate X characters from end of title in file name until the full file name is less than _MAX_FILENAME_LENGTH bytes.
				$dirName = parent::_CONVERTED_FILEDIR . $videoInfo['host_abbrev'] . '/' . $videoInfo['id'] . '/';
				if (!is_dir(realpath($dirName))) mkdir($dirName, 0777, true);
				$this->_convertedFileName = $dirName . $fname;
			}
			//die($this->_convertedFileName);
		}

		public function GetVidSourceUrls()
		{
			return $this->_vidSourceUrls;
		}
		private function SetVidSourceUrls()
		{
			$extractor = $this->GetExtractor();
			$this->_vidSourceUrls = $extractor->ExtractVidSourceUrls();
		}

		public function GetTempVidFileName()
		{
			return $this->_tempVidFileName;
		}
		private function SetTempVidFileName()
		{
			$videoHost = $this->GetCurrentVidHost();
			if (!empty($videoHost))
			{
				$vidHosts = array_values(array_filter($this->GetVideoHosts(), function($host) use ($videoHost) {return $host['name'] == $videoHost;}));
				$this->_tempVidFileName = parent::_TEMPVIDDIR . $this->_uniqueID . '.' . $vidHosts[0]['src_video_type'];
			}
			//die($this->_tempVidFileName);
		}

		public function GetUniqueID()
		{
			return $this->_uniqueID;
		}

		public function GetConvertedFileTypes()
		{
			return $this->_convertedFileTypes;
		}

		public function GetVideoHosts()
		{
			return $this->_videoHosts;
		}

		public function GetCurrentVidHost()
		{
			return $this->_currentVidHost;
		}
		public function SetCurrentVidHost($hostName)
		{
			$this->_currentVidHost = $hostName;
		}

		public function GetVidInfo()
		{
			return $this->_vidInfo;
		}

		public function GetConvertedFileType()
		{
			return $this->_convertedFileType;
		}
		private function SetConvertedFileType($ftype)
		{
			$this->_convertedFileType = $ftype;
		}

		public function GetConvertedFileCategory()
		{
			return $this->_convertedFileCategory;
		}
		private function SetConvertedFileCategory($fcat)
		{
			$this->_convertedFileCategory = $fcat;
		}

		public function GetConvertedFileQuality()
		{
			return $this->_convertedFileQuality;
		}
		private function SetConvertedFileQuality($quality)
		{
			$this->_convertedFileQuality = $quality;
		}

		public function GetConvertedFileVolume()
		{
			return $this->_convertedFileVolume;
		}
		private function SetConvertedFileVolume($volume)
		{
			$this->_convertedFileVolume = $volume;
		}

		public function GetExtractor()
		{
			return $this->_extractor;
		}
		private function SetExtractor($vidHostName)
		{
			$this->_extractor = new $vidHostName($this);
		}

		public function GetSkipConversion()
		{
			return $this->_skipConversion;
		}

		public function GetFFmpegCommand()
		{
			return $this->_ffmpegCommand;
		}

		public function GetValidationError()
		{
			return $this->_validationError;
		}

		public function GetOutgoingIP()
		{
			return $this->_outgoingIP;
		}
		public function SetOutgoingIP()
		{
			$ips = Database::Find(parent::_DB_IPS_TABLE, array('order' => array('usage_count')));
			$this->_outgoingIP = $ips[0]['ip'];
			Database::Save(parent::_DB_IPS_TABLE, array('id' => $ips[0]['id'], 'usage_count' => ++$ips[0]['usage_count']));
		}
		#endregion
	}

?>
