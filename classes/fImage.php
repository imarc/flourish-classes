<?php
/**
 * Represents an image on the filesystem, also provides image manipulation functionality
 * 
 * @copyright  Copyright (c) 2007-2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fImage
 * 
 * @version    1.0.0b
 * @changes    1.0.0b  The initial implementation [wb, 2007-12-19]
 */
class fImage extends fFile
{
	/**
	 * If we are using the imagemagick processor, this stores the path to the binaries
	 * 
	 * @var string
	 */
	static private $imagemagick_dir = NULL;
	
	/**
	 * A custom tmp path to use for ImageMagick
	 * 
	 * @var string
	 */
	static private $imagemagick_temp_dir = NULL;
	
	/**
	 * The processor to use for the image manipulation
	 * 
	 * @var string
	 */
	static private $processor = NULL;
	
	
	/**
	 * Checks to make sure we can get to and execute the ImageMagick convert binary
	 * 
	 * @param  string $path  The path to ImageMagick on the filesystem
	 * @return void
	 */
	static private function checkImageMagickBinary($path)
	{
		// Make sure we can execute the convert binary
		if (self::isSafeModeExecDirRestricted($path)) {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					'Safe mode is turned on and the ImageMagick convert binary is not in the directory defined by the safe_mode_exec_dir ini setting or safe_mode_exec_dir is not set - safe_mode_exec_dir is currently %s.',
					fCore::dump(ini_get('safe_mode_exec_dir'))
				)
			);
		}
		
		if (self::isOpenBaseDirRestricted($path)) {
			exec($path . 'convert -version', $executable);
		} else {
			$executable = is_executable($path . 'convert');
		}
		
		if (!$executable) {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					'The ImageMagick convert binary located in the directory %s does not exist or is not executable',
					fCore::dump($path)
				)
			);
		}
	}
	
	
	/**
	 * Creates an image on the filesystem and returns an object representing it
	 * 
	 * This operation will be reverted by a filesystem transaction being rolled
	 * back.
	 * 
	 * @throws fValidationException
	 * 
	 * @param  string $file_path  The path to the new image
	 * @param  string $contents   The contents to write to the image
	 * @return fImage
	 */
	static public function create($file_path, $contents)
	{
		if (empty($file_path)) {
			fCore::toss(
				'fValidationException',
				fGrammar::compose('No filename was specified')
			);
		}
		
		if (file_exists($file_path)) {
			fCore::toss(
				'fValidationException',
				fGrammar::compose(
					'The image specified, %s, already exists',
					fCore::dump($file_path)
				)
			);
		}
		
		$directory = fFilesystem::getPathInfo($file_path, 'dirname');
		if (!is_writable($directory)) {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					'The file path specified, %s, is inside of a directory that is not writable',
					fCore::dump($file_path)
				)
			);
		}
		
		file_put_contents($file_path, $contents);
		
		$image = new fImage($file_path);
		
		fFilesystem::recordCreate($image);
		
		return $image;
	}
	
	
	/**
	 * Determines what processor to use for image manipulation
	 * 
	 * @return void
	 */
	static private function determineProcessor()
	{
		// Determine what processor to use
		if (self::$processor === NULL) {
			
			// Look for imagemagick first since it can handle more than GD
			try {
				
				$locations = array(
					'solaris'    => array(
						'/opt/local/bin/',
						'/opt/bin/',
						'/opt/csw/bin/'
					),
					'linux/unix' => array(
						'/usr/local/bin/',
						'/usr/bin/'
					)
				);
				
				switch ($os = fCore::getOS()) {
					case 'windows':
						$win_search = 'dir /B "C:\Program Files\ImageMagick*"';
						exec($win_search, $win_output);
						$win_output = trim(join("\n", $win_output));
						 
						if (!$win_output || stripos($win_output, 'File not found') !== FALSE) {
							throw new Exception();
						}
						 
						$path = 'C:\Program Files\\' . $win_output . '\\';
						break;
						
					case 'linux/unix':
					case 'solaris':
						$found = FALSE;
						foreach($locations[$os] as $location) {
							if (self::isSafeModeExecDirRestricted($location)) {
								continue;
							}
							if (self::isOpenBaseDirRestricted($location)) {
								exec($location . 'convert -version', $output);
								if ($output) {
									$found = TRUE;
									$path  = $location;
									break;
								}
							} elseif (is_executable($location . 'convert')) {
								$found = TRUE;
								$path  = $location;
								break;
							}
						}
						
						// We have no fallback in solaris
						if ($os == 'solaris' && !$found) {
							throw new Exception();
						}
						
						// On most linux/unix we can try whereis
						if ($os == 'linux/unix' && !$found) {
							$nix_search = 'whereis -b convert';
							exec($nix_search, $nix_output);
							$nix_output = trim(str_replace('convert:', '', join("\n", $nix_output)));
							
							if (empty($nix_output)) {
								throw new Exception();
							}
						
							$path = preg_replace('#^(.*)convert$#i', '\1', $nix_output);
						}
						break;
					
					default:
						$path = NULL;
				}
				
				self::checkImageMagickBinary($path);
				
				self::$imagemagick_dir = $path;
				self::$processor = 'imagemagick';
				
			} catch (Exception $e) {
				
				// Look for GD last since it does not support tiff files
				if (function_exists('gd_info')) {
					
					self::$processor = 'gd';
				
				} else {
					self::$processor = 'none';
				}
			}
		}
	}
	
	
	/**
	 * Returns an array of acceptable mimetype for the processor installed
	 * 
	 * @internal
	 * 
	 * @return array  The mimetypes that the installed processor can manipulate
	 */
	static public function getCompatibleMimetypes()
	{
		self::determineProcessor();
		
		$mimetypes = array('image/gif', 'image/jpeg', 'image/pjpeg', 'image/png');
		
		if (self::$processor == 'imagemagick') {
			$mimetypes[] = 'image/tiff';
		}
		
		return $mimetypes;
	}
	
	
	/**
	 * Gets the dimensions and type of an image stored on the filesystem
	 * 
	 * The 'type' element will be one of the following:
	 *  - {null} (File type is not supported)
	 *  - 'jpg'
	 *  - 'gif'
	 *  - 'png'
	 *  - 'tif'
	 * 
	 * @throws fValidationException
	 * 
	 * @param  string $image_path  The path to the image to get stats for
	 * @param  string $element     The element to retrieve ('type', 'width', 'height')
	 * @return array  An associative array: 'type' => {mixed}, 'width' => {integer}, 'height' => {integer}
	 */
	static protected function getInfo($image_path, $element=NULL)
	{
		$image_info = @getimagesize($image_path);
		if ($image_info == FALSE) {
			fCore::toss(
				'fValidationException',
				fGrammar::compose(
					'The file specified, %s, is not an image',
					fCore::dump($image_path)
				)
			);
		}
		
		$valid_elements = array('type', 'width', 'height');
		if ($element !== NULL && !in_array($element, $valid_elements)) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The element specified, %1$s, is invalid. Must be one of: %2$s.',
					fCore::dump($element),
					join(', ', $valid_elements)
				)
			);
		}
		
		$types = array(IMAGETYPE_GIF     => 'gif',
					   IMAGETYPE_JPEG    => 'jpg',
					   IMAGETYPE_PNG     => 'png',
					   IMAGETYPE_TIFF_II => 'tif',
					   IMAGETYPE_TIFF_MM => 'tif');
		
		$output           = array();
		$output['width']  = $image_info[0];
		$output['height'] = $image_info[1];
		if (isset($types[$image_info[2]])) {
			$output['type'] = $types[$image_info[2]];
		} else {
			$output['type'] = NULL;
		}
		
		if ($element !== NULL) {
			return $output[$element];
		}
		
		return $output;
	}
	
	
	/**
	 * Checks to make sure the class can handle the image file specified
	 * 
	 * @internal
	 * 
	 * @param  string $image  The image to check for incompatibility
	 * @return boolean  If the image is compatible with the image manipulation facility on the server
	 */
	static public function isImageCompatible($image)
	{
		self::determineProcessor();
		
		if (!file_exists($image)) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The image specified, %s, does not exist',
					fCore::dump($image)
				)
			);
		}
		
		try {
			$info = self::getInfo($image);
		
			if ($info['type'] === NULL || ($info['type'] == 'tif' && self::$processor == 'gd')) {
				return FALSE;
			}
		} catch (fValidationException $e) {
			return FALSE;
		}
		
		return TRUE;
	}
	
	
	/**
	 * Checks if the path specified is restricted by open basedir
	 * 
	 * @param  string $path  The path to check
	 * @return boolean  If the path is restricted by open_basedir
	 */
	static private function isOpenBaseDirRestricted($path)
	{
		if (ini_get('open_basedir')) {
			$open_basedirs = explode((fCore::getOS() == 'windows') ? ';' : ':', ini_get('open_basedir'));
			$found = FALSE;
			
			foreach ($open_basedirs as $open_basedir) {
				if (strpos($path, $open_basedir) === 0) {
					$found = TRUE;
				}
			}
			
			if (!$found) {
				return TRUE;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Checks if the path specified is restricted by the safe mode exec dir restriction
	 * 
	 * @param  string $path  The path to check
	 * @return boolean  If the path is restricted by safe_mode_exec_dir
	 */
	static private function isSafeModeExecDirRestricted($path)
	{
		if (!in_array(strtolower(ini_get('safe_mode')), array('0', '', 'off'))) {
			$exec_dir = ini_get('safe_mode_exec_dir');
			if (!$exec_dir || stripos($path, $exec_dir) === FALSE) {
				return TRUE;
			}
		}
		return FALSE;
	}
	
	
	/**
	 * Sets the directory the ImageMagick binary is installed in and tells the class to use ImageMagick even if GD is installed
	 * 
	 * @param  string $directory  The directory ImageMagick is installed in
	 * @return void
	 */
	static public function setImageMagickDirectory($directory)
	{
		$directory = fDirectory::makeCanonical($directory);
		
		self::checkImageMagickBinary($directory);
		
		self::$imagemagick_dir = $directory;
		self::$processor = 'imagemagick';
	}
	
	
	/**
	 * Sets a custom directory to use for the ImageMagick temporary files
	 * 
	 * @param  string $temp_dir  The directory to use for the ImageMagick temp dir
	 * @return void
	 */
	static public function setImageMagickTempDir($temp_dir)
	{
		$temp_dir = new fDirectory($temp_dir);
		if (!$temp_dir->isWritable()) {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					'The ImageMagick temp directory specified, %s, does not appear to be writable',
					$temp_dir->getPath()
				)
			);
		}
		self::$imagemagick_temp_dir = $temp_dir->getPath();
	}
	
	
	/**
	 * The modifications to perform on the image when it is saved
	 * 
	 * @var array
	 */
	private $pending_modifications = array();
	
	
	/**
	 * Creates an object to represent an image on the filesystem
	 * 
	 * @throws fValidationException
	 * 
	 * @param  string $file_path  The path to the image
	 * @return fImage
	 */
	public function __construct($file_path)
	{
		self::determineProcessor();
		
		try {
			
			if (!self::isImageCompatible($file_path)) {
				$valid_image_types = array('GIF', 'JPG', 'PNG');
				if (self::$processor == 'imagemagick') {
					$valid_image_types[] = 'TIF';
				}
				fCore::toss(
					'fValidationException',
					fGrammar::compose(
						'The image specified, %1$s, is not a valid %2$s file',
						fCore::dump($file_path),
						fGrammar::joinArray($valid_image_types, 'or')
					)
				);
			}
			parent::__construct($file_path);
				
		} catch (fValidationException $e) {
			$this->file = NULL;
			$this->exception = $e;
		}
	}
	
	
	/**
	 * Crops the biggest area possible from the center of the image that matches the ratio provided
	 * 
	 * Crop does not occur until {@link fImage::saveChanges()} is called.
	 * 
	 * @param  numeric $ratio_width   The width to crop the image to
	 * @param  numeric $ratio_height  The height to crop the image to
	 * @return void
	 */
	public function cropToRatio($ratio_width, $ratio_height)
	{
		$this->tossIfException();
		
		// Make sure the user input is valid
		if ((!is_numeric($ratio_width) && $ratio_width !== NULL) || $ratio_width < 0) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The ratio width specified, %s, is not a number or is less than or equal to zero',
					fCore::dump($ratio_width)
				)
			);
		}
		if ((!is_numeric($ratio_height) && $ratio_height !== NULL) || $ratio_height < 0) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The ratio height specified, %s, is not a number or is less than or equal to zero',
					fCore::dump($ratio_height)
				)
			);
		}
		
		// Get the new dimensions
		$dim = $this->getCurrentDimensions();
		$orig_width  = $dim['width'];
		$orig_height = $dim['height'];
		
		$orig_ratio = $orig_width / $orig_height;
		$new_ratio  = $ratio_width / $ratio_height;
			
		if ($orig_ratio > $new_ratio) {
			$new_height = $orig_height;
			$new_width  = (int) ($new_ratio * $new_height);
		} else {
			$new_width  = $orig_width;
			$new_height = (int) ($new_width / $new_ratio);
		}
			
		// Figure out where to crop from
		$crop_from_x = floor(($orig_width - $new_width) / 2);
		$crop_from_y = floor(($orig_height - $new_height) / 2);
		
		$crop_from_x = ($crop_from_x < 0) ? 0 : $crop_from_x;
		$crop_from_y = ($crop_from_y < 0) ? 0 : $crop_from_y;
			
		// If nothing changed, don't even record the modification
		if ($orig_width == $new_width && $orig_height == $new_height) {
			return;
		}
		
		// Record what we are supposed to do
		$this->pending_modifications[] = array(
			'operation'  => 'crop',
			'start_x'    => $crop_from_x,
			'start_y'    => $crop_from_y,
			'width'      => $new_width,
			'height'     => $new_height,
			'old_width'  => $orig_width,
			'old_height' => $orig_height
		);
	}
	
	
	/**
	 * Converts the image to grayscale
	 * 
	 * Desaturation does not occur until {@link fImage::saveChanges()} is called.
	 * 
	 * @return void
	 */
	public function desaturate()
	{
		$this->tossIfException();
		
		$dim = $this->getCurrentDimensions();
		
		// Record what we are supposed to do
		$this->pending_modifications[] = array(
			'operation'  => 'desaturate',
			'width'      => $dim['width'],
			'height'     => $dim['height']
		);
	}
	
	
	/**
	 * Gets the dimensions of the image as of the last modification
	 * 
	 * @return array  An associative array: 'width' => {integer}, 'height' => {integer}
	 */
	private function getCurrentDimensions()
	{
		if (empty($this->pending_modifications)) {
			$output = self::getInfo($this->file);
			unset($output['type']);
		
		} else {
			$last_modification = $this->pending_modifications[sizeof($this->pending_modifications)-1];
			$output['width']  = $last_modification['width'];
			$output['height'] = $last_modification['height'];
		}
		
		return $output;
	}
	
	
	/**
	 * Returns the height of the image
	 * 
	 * @return integer  The height of the image in pixels
	 */
	public function getHeight()
	{
		return self::getInfo($this->file, 'height');
	}
	
	
	/**
	 * Returns the type of the image
	 * 
	 * @return string  The type of the image - 'jpg', 'gif', 'png' or 'tif'
	 */
	public function getType()
	{
		return self::getInfo($this->file, 'type');
	}
	
	
	/**
	 * Returns the width of the image
	 * 
	 * @return integer  The width of the image in pixels
	 */
	public function getWidth()
	{
		return self::getInfo($this->file, 'width');
	}
	
	
	/**
	 * Checks if the current image is an animated gif
	 * 
	 * @return boolean  If the image is an animated gif
	 */
	private function isAnimatedGif()
	{
		$info = self::getInfo($this->file);
		if ($info['type'] == 'gif') {
			if (preg_match('#\x00\x21\xF9\x04.{4}\x00\x2C#s', file_get_contents($this->file))) {
				return TRUE;
			}
		}
		return FALSE;
	}
	
	
	/**
	 * Processes the current image using GD
	 * 
	 * @param  string $output_file  The file to save the image to
	 * @return void
	 */
	private function processWithGD($output_file)
	{
		if (empty($this->pending_modifications)) {
			return;
		}
		
		$info = self::getInfo($this->file);
		
		switch ($info['type']) {
			case 'gif':
				$gd_res = imagecreatefromgif($this->file);
				break;
			case 'jpg':
				$gd_res = imagecreatefromjpeg($this->file);
				break;
			case 'png':
				$gd_res = imagecreatefrompng($this->file);
				break;
		}
		
		
		foreach ($this->pending_modifications as $mod) {
			
			// Perform the resize operation
			if ($mod['operation'] == 'resize') {
				
				$new_gd_res = imagecreatetruecolor($mod['width'], $mod['height']);
				imagecopyresampled($new_gd_res,       $gd_res,
								   0,                 0,
								   0,                 0,
								   $mod['width'],     $mod['height'],
								   $mod['old_width'], $mod['old_height']);
				imagedestroy($gd_res);
				$gd_res = $new_gd_res;
				
				
			// Perform the crop operation
			} elseif ($mod['operation'] == 'crop') {
			
				$new_gd_res = imagecreatetruecolor($mod['width'], $mod['height']);
				imagecopyresampled($new_gd_res,       $gd_res,
								   0,                 0,
								   $mod['start_x'],   $mod['start_y'],
								   $mod['width'],     $mod['height'],
								   $mod['width'],     $mod['height']);
				imagedestroy($gd_res);
				$gd_res = $new_gd_res;
				
				
			// Perform the desaturate operation
			} elseif ($mod['operation'] == 'desaturate') {
			
				$new_gd_res = imagecreate($mod['width'], $mod['height']);
				
				// Create a palette of grays
				$grays = array();
				for ($i=0; $i < 256; $i++) {
					$grays[$i] = imagecolorallocate($new_gd_res, $i, $i, $i);
				}
				
				// Loop through every pixel and convert the rgb values to grays
				for ($x=0; $x < $mod['width']; $x++) {
					for ($y=0; $y < $mod['height']; $y++) {
						
						$color = imagecolorat($gd_res, $x, $y);
						$red   = ($color >> 16) & 0xFF;
						$green = ($color >> 8) & 0xFF;
						$blue  = $color & 0xFF;
						
						// Get the appropriate gray (http://en.wikipedia.org/wiki/YIQ)
						$yiq = round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));
						imagesetpixel($new_gd_res, $x, $y, $grays[$yiq]);
					}
				}
				
				imagedestroy($gd_res);
				$gd_res = $new_gd_res;
			}
		}
		
		// Save the file
		$info = fFilesystem::getPathInfo($output_file);
		
		switch ($info['extension']) {
			case 'gif':
				imagegif($gd_res, $output_file);
				break;
			case 'jpg':
				imagejpeg($gd_res, $output_file, 90);
				break;
			case 'png':
				imagepng($gd_res, $output_file);
				break;
		}
	}
	
	
	/**
	 * Processes the current image using ImageMagick
	 * 
	 * @param  string $output_file  The file to save the image to
	 * @return void
	 */
	private function processWithImageMagick($output_file)
	{
		if (empty($this->pending_modifications)) {
			return;
		}
		
		$info = self::getInfo($this->file);
		
		$command_line  = escapeshellcmd(self::$imagemagick_dir . 'convert');
		
		if (self::$imagemagick_temp_dir) {
			$command_line .= ' -set registry:temporary-path ' . escapeshellarg(self::$imagemagick_temp_dir) . ' ';
		}
		
		$command_line .= ' ' . escapeshellarg($this->file) . ' ';
		
		// Animated gifs need to be coalesced
		if ($this->isAnimatedGif()) {
			$command_line .= ' -coalesce ';
		}
		
		// TIFF files should be set to a depth of 8
		if ($info['type'] == 'tif') {
			$command_line .= ' -depth 8 ';
		}
		
		foreach ($this->pending_modifications as $mod) {
			
			// Perform the resize operation
			if ($mod['operation'] == 'resize') {
				$command_line .= ' -resize ' . $mod['width'] . 'x' . $mod['height'] . ' ';
				
			// Perform the crop operation
			} elseif ($mod['operation'] == 'crop') {
				$command_line .= ' -crop ' . $mod['width'] . 'x' . $mod['height'];
				$command_line .= '+' . $mod['start_x'] . '+' . $mod['start_y'];
				$command_line .= ' -repage ' . $mod['width'] . 'x' . $mod['height'] . '+0+0 ';
				
			// Perform the desaturate operation
			} elseif ($mod['operation'] == 'desaturate') {
				$command_line .= ' -colorspace GRAY ';
			}
		}
		
		// Default to the RGB colorspace
		if (strpos($command_line, ' -colorspace ')) {
			$command_line .= ' -colorspace RGB ';
		}
		
		// Set up jpeg compression
		$info = fFilesystem::getPathInfo($output_file);
		if ($info['extension'] == 'jpg') {
			$command_line .= ' -compress JPEG -quality 90 ';
		}
		
		$command_line .= ' ' . escapeshellarg($output_file);
		
		exec($command_line);
	}
	
	
	/**
	 * Sets the image to be resized proportionally to a specific sized canvas
	 * 
	 * Will only size down an image. Resize does not occur until
	 * {@link fImage::saveChanges()} is called.
	 * 
	 * @param  integer $canvas_width   The width of the canvas to fit the image on, 0 for no constraint
	 * @param  integer $canvas_height  The height of the canvas to fit the image on, 0 for no constraint
	 * @return void
	 */
	public function resize($canvas_width, $canvas_height)
	{
		$this->tossIfException();
		
		// Make sure the user input is valid
		if ((!is_int($canvas_width) && $canvas_width !== NULL) || $canvas_width < 0) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The canvas width specified, %s, is not an integer or is less than zero',
					fCore::dump($canvas_width)
				)
			);
		}
		if ((!is_int($canvas_height) && $canvas_height !== NULL) || $canvas_height < 0) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The canvas height specified, %s is not an integer or is less than zero',
					fCore::dump($canvas_height)
				)
			);
		}
		if ($canvas_width == 0 && $canvas_height == 0) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The canvas width and canvas height are both zero, so no resizing will occur'
				)
			);
		}
		
		// Calculate what the new dimensions will be
		$dim = $this->getCurrentDimensions();
		$orig_width  = $dim['width'];
		$orig_height = $dim['height'];
		
		if ($canvas_width == 0) {
			$new_height = $canvas_height;
			$new_width  = (int) (($new_height/$orig_height) * $orig_width);
		
		} elseif ($canvas_height == 0) {
			$new_width  = $canvas_width;
			$new_height = (int) (($new_width/$orig_width) * $orig_height);
		
		} else {
			$orig_ratio   = $orig_width/$orig_height;
			$canvas_ratio = $canvas_width/$canvas_height;
			
			if ($canvas_ratio > $orig_ratio) {
				$new_height = $canvas_height;
				$new_width  = (int) ($orig_ratio * $new_height);
			} else {
				$new_width  = $canvas_width;
				$new_height = (int) ($new_width / $orig_ratio);
			}
		}
		
		// If the size did not go down, don't even record the modification
		if ($orig_width <= $new_width || $orig_height <= $new_height) {
			return;
		}
		
		// Record what we are supposed to do
		$this->pending_modifications[] = array(
			'operation'  => 'resize',
			'width'      => $new_width,
			'height'     => $new_height,
			'old_width'  => $orig_width,
			'old_height' => $orig_height
		);
	}
	
	
	/**
	 * Saves any changes to the image
	 * 
	 * If the file type is different than the current one, removes the current
	 * file once the new one is created.
	 * 
	 * This operation will be reverted by a filesystem transaction being rolled back.
	 * If a transaction is in progress and the new image type causes a new file
	 * to be created, the old file will not be deleted until the transaction is
	 * committed.
	 * 
	 * @param  string $new_image_type  The new file type for the image, can be 'jpg', 'gif' or 'png'
	 * @return void
	 */
	public function saveChanges($new_image_type=NULL)
	{
		$this->tossIfException();
		
		if (self::$processor == 'none') {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					"The changes to the image can't be saved because neither the GD extension or ImageMagick appears to be installed on the server"
				)
			);
		}
		
		$info = self::getInfo($this->file);
		if ($info['type'] == 'tif' && self::$processor == 'gd') {
			fCore::toss(
				'fEnvironmentException',
				fGrammar::compose(
					'The image specified, %s, is a TIFF file and the GD extension can not handle TIFF files. Please install ImageMagick if you wish to manipulate TIFF files.',
					fCore::dump($this->file)
				)
			);
		}
		
		$valid_image_types = array('jpg', 'gif', 'png');
		if ($new_image_type !== NULL && !in_array($new_image_type, $valid_image_types)) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The new image type specified, %1$s, is invalid. Must be one of: %2$s.',
					fCore::dump($new_image_type),
					join(', ', $valid_image_types)
				)
			);
		}
		
		if ($new_image_type) {
			$output_file = fFilesystem::createUniqueName($this->file, $new_image_type);
		} else {
			$output_file = $this->file;
		}
		
		// Wrap changes to the image into the filesystem transaction
		if ($output_file == $this->file && fFilesystem::isInsideTransaction()) {
			fFilesystem::recordWrite($this);
		}
		
		if (self::$processor == 'gd') {
			$this->processWithGD($output_file);
		} elseif (self::$processor == 'imagemagick') {
			$this->processWithImageMagick($output_file);
		}
		
		$old_file = $this->file;
		fFilesystem::updateFilenameMap($this->file, $output_file);
		
		// If we created a new image, delete the old one
		if ($output_file != $old_file) {
			$old_image = new fImage($old_file);
			$old_image->delete();
		}
		
		$this->pending_modifications = array();
	}
}



/**
 * Copyright (c) 2007-2008 William Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */