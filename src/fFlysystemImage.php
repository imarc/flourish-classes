<?php

use Imarc\VAL\Traits\Flourish\hasFlysystem;
use Imarc\VAL\Traits\Flourish\hasTempDir;

/**
 * Represents an image on the filesystem, also provides image manipulation functionality.
 */
class fFlysystemImage extends fFlysystemFile
{
    use hasFlysystem;
    use hasTempDir;

    // The following constants allow for nice looking callbacks to static methods
    public const create = 'fImage::create';

    public const getCompatibleMimetypes = 'fImage::getCompatibleMimetypes';

    public const isImageCompatible = 'fImage::isImageCompatible';

    public const reset = 'fImage::reset';

    public const setImageMagickDirectory = 'fImage::setImageMagickDirectory';

    public const setImageMagickTempDir = 'fImage::setImageMagickTempDir';

    /**
     * If we are using the ImageMagick processor, this stores the path to the binaries.
     *
     * @var string
     */
    private static $imagemagick_dir;

    /**
     * A custom tmp path to use for ImageMagick.
     *
     * @var string
     */
    private static $imagemagick_temp_dir;

    /**
     * The processor to use for the image manipulation.
     *
     * @var string
     */
    private static $processor;

    /**
     * The modifications to perform on the image when it is saved.
     *
     * @var array
     */
    private $pending_modifications = [];

    /**
     * Creates an object to represent an image on the filesystem.
     *
     * @param string $file_path   The path to the image
     * @param bool   $skip_checks If file checks should be skipped, which improves performance, but may cause undefined behavior - only skip these if they are duplicated elsewhere
     * @param mixed  $path
     *
     * @throws fValidationException When no image was specified, when the image does not exist or when the path specified is not an image
     *
     * @return fImage
     */
    public function __construct($path, $skip_checks = false)
    {
        self::determineProcessor();

        parent::__construct($path, $skip_checks);

        if (! self::isImageCompatible($path)) {
            $valid_image_types = ['GIF', 'JPG', 'PNG'];
            if (self::$processor == 'imagemagick') {
                $valid_image_types[] = 'TIF';
            }

            throw new fValidationException(
                'The image specified, %1$s, is not a valid %2$s file',
                $path,
                fGrammar::joinArray($valid_image_types, 'or')
            );
        }
    }

    /**
     * Creates an image on the filesystem and returns an object representing it.
     *
     * This operation will be reverted by a filesystem transaction being rolled
     * back.
     *
     * @param string $file_path The path to the new image
     * @param string $contents  The contents to write to the image
     * @param mixed  $path
     * @param mixed  $file
     *
     * @throws fValidationException When no image was specified or when the image already exists
     */
    public static function create($path, $file): self
    {
        if (static::getFlysystem()->has($path)) {
            throw new fValidationException(
                'The image specified, %s, already exists',
                $path
            );
        }

        parent::create($path, $file);

        $image = new self($path);

        fFilesystem::recordCreate($image);

        return $image;
    }

    /**
     * Returns an array of acceptable mime types for the processor that was detected.
     *
     * @internal
     *
     * @return array The mime types that the detected image processor can manipulate
     */
    public static function getCompatibleMimetypes()
    {
        return fImage::getCompatibleMimetypes();
    }

    /**
     * Gets the dimensions and type of an image stored on the filesystem.
     *
     * The `'type'` key will have one of the following values:
     *
     *  - `{null}` (File type is not supported)
     *  - `'jpg'`
     *  - `'gif'`
     *  - `'png'`
     *  - `'tif'`
     *
     * @param string $image_path The path to the image to get stats for
     * @param string $element    The element to retrieve: `'type'`, `'width'`, `'height'`
     *
     * @throws fValidationException When the file specified is not an image
     *
     * @return mixed An associative array: `'type' => {mixed}, 'width' => {integer}, 'height' => {integer}`, or the element specified
     */
    public static function getInfo($image_path, $element = null)
    {
        $extension = strtolower(fFlysystem::getPathInfo($image_path, 'extension'));

        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff'])) {
            $type = self::getImageType($image_path);
            if ($type === null) {
                throw new fValidationException(
                    'The file specified, %s, does not appear to be an image',
                    $image_path
                );
            }
        }

        $valid_elements = ['type', 'width', 'height'];
        if ($element !== null && ! in_array($element, $valid_elements)) {
            throw new fProgrammerException(
                'The element specified, %1$s, is invalid. Must be one of: %2$s.',
                $element,
                implode(', ', $valid_elements)
            );
        }

        fCore::startErrorCapture(E_WARNING);
        fCore::stopErrorCapture();
        $image_info = getimagesize(static::getFlysystem()->getObjectUrl($image_path));

        if ($image_info == false) {
            throw new fValidationException(
                'The file specified, %s, is not an image',
                $image_path
            );
        }

        $types = [
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_TIFF_II => 'tif',
            IMAGETYPE_TIFF_MM => 'tif',
        ];

        $output = [];
        $output['width'] = $image_info[0];
        $output['height'] = $image_info[1];
        if (isset($types[$image_info[2]])) {
            $output['type'] = $types[$image_info[2]];
        } else {
            $output['type'] = null;
        }

        if ($element !== null) {
            return $output[$element];
        }

        return $output;
    }

    public function imageType(): string|null
    {
        return static::getImageType($this->getPath());
    }

    /**
     * Checks to make sure the class can handle the image file specified.
     *
     * @internal
     *
     * @param string $image The image to check for incompatibility
     *
     * @throws fValidationException When the image specified does not exist
     *
     * @return bool If the image is compatible with the detected image processor
     */
    public static function isImageCompatible($image)
    {
        self::determineProcessor();

        if (! static::exists($image)) {
            throw new fValidationException(
                'The image specified, %s, does not exist',
                $image
            );
        }

        $type = static::getImageType($image);

        if ($type === null || ($type == 'tif' && static::$processor == 'gd')) {
            return false;
        }

        return true;
    }

    /**
     * Resets the configuration of the class.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$imagemagick_dir = null;
        self::$imagemagick_temp_dir = null;
        self::$processor = null;
    }

    /**
     * Sets the directory the ImageMagick binary is installed in and tells the class to use ImageMagick even if GD is installed.
     *
     * @param string $directory The directory ImageMagick is installed in
     */
    public static function setImageMagickDirectory($directory): void
    {
        $directory = fDirectory::makeCanonical($directory);

        self::checkImageMagickBinary($directory);

        self::$imagemagick_dir = $directory;
        self::$processor = 'imagemagick';
    }

    /**
     * Sets a custom directory to use for the ImageMagick temporary files.
     *
     * @param string $temp_dir The directory to use for the ImageMagick temp dir
     */
    public static function setImageMagickTempDir($temp_dir): void
    {
        $temp_dir = new fDirectory($temp_dir);
        if (! $temp_dir->isWritable()) {
            throw new fEnvironmentException(
                'The ImageMagick temp directory specified, %s, does not appear to be writable',
                $temp_dir->getPath()
            );
        }
        self::$imagemagick_temp_dir = $temp_dir->getPath();
    }

    /**
     * Prevents a programmer from trying to append an image.
     *
     * @param mixed $data The data to append to the image
     */
    public function append($data)
    {
        throw new fProgrammerException('It is not possible to append an image');
    }

    /**
     * Crops the image by the exact pixel dimensions specified.
     *
     * The crop does not occur until ::saveChanges() is called.
     *
     * @param numeric $crop_from_x The number of pixels from the left of the image to start the crop from
     * @param numeric $crop_from_y The number of pixels from the top of the image to start the crop from
     * @param numeric $new_width   The width in pixels to crop the image to
     * @param numeric $new_height  The height in pixels to crop the image to
     *
     * @return static The image object, to allow for method chaining
     */
    public function crop($crop_from_x, $crop_from_y, $new_width, $new_height): static
    {
        $this->tossIfDeleted();

        // Get the original dimensions for our parameter checking
        $dim = $this->getCurrentDimensions();
        $orig_width = $dim['width'];
        $orig_height = $dim['height'];

        // Make sure the user input is valid
        if (! is_numeric($crop_from_x) || $crop_from_x < 0 || $crop_from_x > $orig_width - 1) {
            throw new fProgrammerException(
                'The crop-from x specified, %s, is not a number, is less than zero, or would result in a zero-width image',
                $crop_from_x
            );
        }
        if (! is_numeric($crop_from_y) || $crop_from_y < 0 || $crop_from_y > $orig_height - 1) {
            throw new fProgrammerException(
                'The crop-from y specified, %s, is not a number, is less than zero, or would result in a zero-height image',
                $crop_from_y
            );
        }

        if (! is_numeric($new_width) || $new_width <= 0 || $crop_from_x + $new_width > $orig_width) {
            throw new fProgrammerException(
                'The new width specified, %1$s, is not a number, is less than or equal to zero, or is larger than can be cropped with the specified crop-from x of %2$s',
                $new_width,
                $crop_from_x
            );
        }
        if (! is_numeric($new_height) || $new_height <= 0 || $crop_from_y + $new_height > $orig_height) {
            throw new fProgrammerException(
                'The new height specified, %1$s, is not a number, is less than or equal to zero, or is larger than can be cropped with the specified crop-from y of %2$s',
                $new_height,
                $crop_from_y
            );
        }

        // If nothing changed, don't even record the modification
        if ($orig_width == $new_width && $orig_height == $new_height) {
            return $this;
        }

        // Record what we are supposed to do
        $this->pending_modifications[] = [
            'operation' => 'crop',
            'start_x' => $crop_from_x,
            'start_y' => $crop_from_y,
            'width' => $new_width,
            'height' => $new_height,
            'old_width' => $orig_width,
            'old_height' => $orig_height,
        ];

        return $this;
    }

    /**
     * Crops the biggest area possible from the center of the image that matches the ratio provided.
     *
     * The crop does not occur until ::saveChanges() is called.
     *
     * @param numeric $ratio_width  The width ratio to crop the image to
     * @param numeric $ratio_height The height ratio to crop the image to
     *
     * @return static The image object, to allow for method chaining
     */
    public function cropToRatio($ratio_width, $ratio_height): static
    {
        $this->tossIfDeleted();

        // Make sure the user input is valid
        if ((! is_numeric($ratio_width) && $ratio_width !== null) || $ratio_width < 0) {
            throw new fProgrammerException(
                'The ratio width specified, %s, is not a number or is less than or equal to zero',
                $ratio_width
            );
        }
        if ((! is_numeric($ratio_height) && $ratio_height !== null) || $ratio_height < 0) {
            throw new fProgrammerException(
                'The ratio height specified, %s, is not a number or is less than or equal to zero',
                $ratio_height
            );
        }

        // Get the new dimensions
        $dim = $this->getCurrentDimensions();
        $orig_width = $dim['width'];
        $orig_height = $dim['height'];

        $orig_ratio = $orig_width / $orig_height;
        $new_ratio = $ratio_width / $ratio_height;

        if ($orig_ratio > $new_ratio) {
            $new_height = $orig_height;
            $new_width = round($new_ratio * $new_height);
        } else {
            $new_width = $orig_width;
            $new_height = round($new_width / $new_ratio);
        }

        // Figure out where to crop from
        $crop_from_x = floor(($orig_width - $new_width) / 2);
        $crop_from_y = floor(($orig_height - $new_height) / 2);

        $crop_from_x = ($crop_from_x < 0) ? 0 : $crop_from_x;
        $crop_from_y = ($crop_from_y < 0) ? 0 : $crop_from_y;

        // If nothing changed, don't even record the modification
        if ($orig_width == $new_width && $orig_height == $new_height) {
            return $this;
        }

        // Record what we are supposed to do
        $this->pending_modifications[] = [
            'operation' => 'crop',
            'start_x' => $crop_from_x,
            'start_y' => $crop_from_y,
            'width' => $new_width,
            'height' => $new_height,
            'old_width' => $orig_width,
            'old_height' => $orig_height,
        ];

        return $this;
    }

    /**
     * Converts the image to grayscale.
     *
     * Desaturation does not occur until ::saveChanges() is called.
     *
     * @return static The image object, to allow for method chaining
     */
    public function desaturate(): static
    {
        $this->tossIfDeleted();

        $dim = $this->getCurrentDimensions();

        // Record what we are supposed to do
        $this->pending_modifications[] = [
            'operation' => 'desaturate',
            'width' => $dim['width'],
            'height' => $dim['height'],
        ];

        return $this;
    }

    /**
     * Returns the width and height of the image as a two element array.
     *
     * @return array In the format `0 => (integer) {width}, 1 => (integer) {height}`
     */
    public function getDimensions()
    {
        $info = self::getInfo($this->file);

        return [$info['width'], $info['height']];
    }

    /**
     * Returns the height of the image.
     *
     * @return int The height of the image in pixels
     */
    public function getHeight()
    {
        return self::getInfo($this->file, 'height');
    }

    /**
     * Returns the type of the image.
     *
     * @return null|string The type of the image: `'jpg'`, `'gif'`, `'png'`, `'tif'`
     */
    public function getType(): string|null
    {
        return self::getImageType($this->file);
    }

    /**
     * Returns the width of the image.
     *
     * @return int The width of the image in pixels
     */
    public function getWidth()
    {
        return self::getInfo($this->file, 'width');
    }

    /**
     * Checks if the current image is an animated gif.
     *
     * @return bool If the image is an animated gif
     */
    public function isAnimatedGif()
    {
        $type = self::getImageType($this->file);
        if ($type == 'gif') {
            if (preg_match('#\x00\x21\xF9\x04.{4}\x00\x2C#s', $this->read())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sets the image to be resized proportionally to a specific size canvas.
     *
     * Will only size down an image. This method uses resampling to ensure the
     * resized image is smooth in aappearance. Resizing does not occur until
     * ::saveChanges() is called.
     *
     * @param int  $canvas_width   The width of the canvas to fit the image on, `0` for no constraint
     * @param int  $canvas_height  The height of the canvas to fit the image on, `0` for no constraint
     * @param bool $allow_upsizing If the image is smaller than the desired canvas, the image will be increased in size
     *
     * @return static The image object, to allow for method chaining
     */
    public function resize($canvas_width, $canvas_height, $allow_upsizing = false): static
    {
        $this->tossIfDeleted();

        // Make sure the user input is valid
        if ((! is_numeric($canvas_width) && $canvas_width !== null) || $canvas_width < 0) {
            throw new fProgrammerException(
                'The canvas width specified, %s, is not an integer or is less than zero',
                $canvas_width
            );
        }
        if ((! is_numeric($canvas_height) && $canvas_height !== null) || $canvas_height < 0) {
            throw new fProgrammerException(
                'The canvas height specified, %s is not an integer or is less than zero',
                $canvas_height
            );
        }
        if ($canvas_width == 0 && $canvas_height == 0) {
            throw new fProgrammerException(
                'The canvas width and canvas height are both zero, so no resizing will occur'
            );
        }

        // Calculate what the new dimensions will be
        $dim = $this->getCurrentDimensions();
        $orig_width = $dim['width'];
        $orig_height = $dim['height'];

        if ($canvas_width == 0) {
            $new_height = $canvas_height;
            $new_width = round(($new_height / $orig_height) * $orig_width);
        } elseif ($canvas_height == 0) {
            $new_width = $canvas_width;
            $new_height = round(($new_width / $orig_width) * $orig_height);
        } else {
            $orig_ratio = $orig_width / $orig_height;
            $canvas_ratio = $canvas_width / $canvas_height;

            if ($canvas_ratio > $orig_ratio) {
                $new_height = $canvas_height;
                $new_width = round($orig_ratio * $new_height);
            } else {
                $new_width = $canvas_width;
                $new_height = round($new_width / $orig_ratio);
            }
        }

        // If the size did not change, don't even record the modification
        $same_size = $orig_width == $new_width || $orig_height == $new_height;
        $wont_change = ($orig_width < $new_width || $orig_height < $new_height) && ! $allow_upsizing;
        if ($same_size || $wont_change) {
            return $this;
        }

        // Record what we are supposed to do
        $this->pending_modifications[] = [
            'operation' => 'resize',
            'width' => $new_width,
            'height' => $new_height,
            'old_width' => $orig_width,
            'old_height' => $orig_height,
        ];

        return $this;
    }

    /**
     * Saves any changes to the image.
     *
     * If the file type is different than the current one, removes the current
     * file once the new one is created.
     *
     * This operation will be reverted by a filesystem transaction being rolled
     * back. If a transaction is in progress and the new image type causes a
     * new file to be created, the old file will not be deleted until the
     * transaction is committed.
     *
     * @param string $new_image_type The new file format for the image: 'null` (no change), `'jpg'`, `'gif'`, `'png'`
     * @param int    $jpeg_quality   The quality setting to use for JPEG images - this may be ommitted
     * @param bool   $overwrite      If an existing file with the same name and extension should be overwritten
     * @param string  :$new_image_type
     * @param bool :$overwrite
     */
    public function saveChanges($new_image_type = null, $jpeg_quality = 90, $overwrite = false): static
    {
        // This allows ommitting the $jpeg_quality parameter, which is very useful for non-jpegs
        $args = func_get_args();
        if (count($args) == 2 && is_bool($args[1])) {
            $overwrite = $args[1];
            $jpeg_quality = 90;
        }

        $this->tossIfDeleted();
        self::determineProcessor();

        if (self::$processor == 'none') {
            throw new fEnvironmentException(
                "The changes to the image can't be saved because neither the GD extension or ImageMagick appears to be installed on the server"
            );
        }

        $type = self::getImageType($this->file);
        if ($type == 'tif' && self::$processor == 'gd') {
            throw new fEnvironmentException(
                'The image specified, %s, is a TIFF file and the GD extension can not handle TIFF files. Please install ImageMagick if you wish to manipulate TIFF files.',
                $this->file
            );
        }

        $valid_image_types = ['jpg', 'gif', 'png'];
        if ($new_image_type !== null && ! in_array($new_image_type, $valid_image_types)) {
            throw new fProgrammerException(
                'The new image type specified, %1$s, is invalid. Must be one of: %2$s.',
                $new_image_type,
                implode(', ', $valid_image_types)
            );
        }

        if (is_numeric($jpeg_quality)) {
            $jpeg_quality = (int) round($jpeg_quality);
        }

        if (! is_int($jpeg_quality) || $jpeg_quality < 1 || $jpeg_quality > 100) {
            throw new fProgrammerException(
                'The JPEG quality specified, %1$s, is either not an integer, less than %2$s or greater than %3$s.',
                $jpeg_quality,
                1,
                100
            );
        }

        if ($new_image_type && fFlysystem::getPathInfo($this->file, 'extension') != $new_image_type) {
            if ($overwrite) {
                $path_info = fFlysystem::getPathInfo($this->file);
                $output_file = $path_info['dirname'].$path_info['filename'].'.'.$new_image_type;
            } else {
                $output_file = fFlysystem::makeUniqueName($this->file, $new_image_type);
            }

            if ($this->getFlysystem()->has($output_file)) {
                if ($this->getFlysystem()->getVisibility($output_file) === 'private') {
                    throw new fEnvironmentException(
                        'Changes to the image can not be saved because the file, %s, is not writable',
                        $output_file
                    );
                }
            }
        } else {
            $output_file = $this->file;
        }

        // If we don't have any changes and no name change, just exit
        if (! $this->pending_modifications && $output_file == $this->file) {
            return $this;
        }

        // Wrap changes to the image into the filesystem transaction
        if ($output_file == $this->file && fFilesystem::isInsideTransaction()) {
            fFlysystem::recordWrite($this);
        }

        $temp_file = fImage::create($this->getTempDir().$this->getName(), $this->read());
        $old_file = $this->getPath();
        $this->file = $output_file;

        if (self::$processor == 'gd') {
            $this->processWithGD($output_file, $temp_file, $jpeg_quality);
        } elseif (self::$processor == 'imagemagick') {
            $this->processWithImageMagick($output_file, $temp_file, $jpeg_quality);
        }

        fFlysystem::updateFilenameMap($old_file, $output_file);

        // If we created a new image, delete the old one
        if ($output_file != $old_file) {
            $old_image = new self($old_file);
            $old_image->delete();
        }
        $temp_file->delete();

        $this->pending_modifications = [];

        return $this;
    }

    /**
     * @param null|string $path
     *
     * @return bool
     */
    public static function exists(string|null $path = null)
    {
        return static::getFlysystem()->has($path);
    }

    /**
     * Checks to make sure we can get to and execute the ImageMagick convert binary.
     *
     * @param string $path The path to ImageMagick on the filesystem
     */
    private static function checkImageMagickBinary($path): void
    {
        // Make sure we can execute the convert binary
        if (self::isSafeModeExecDirRestricted($path)) {
            throw new fEnvironmentException(
                'Safe mode is turned on and the ImageMagick convert binary is not in the directory defined by the safe_mode_exec_dir ini setting or safe_mode_exec_dir is not set - safe_mode_exec_dir is currently %s.',
                ini_get('safe_mode_exec_dir')
            );
        }

        if (self::isOpenBaseDirRestricted($path)) {
            exec($path.'convert -version', $executable);
        } else {
            $executable = is_executable($path.(fCore::checkOS('windows') ? 'convert.exe' : 'convert'));
        }

        if (! $executable) {
            throw new fEnvironmentException(
                'The ImageMagick convert binary located in the directory %s does not exist or is not executable',
                $path
            );
        }
    }

    /**
     * Determines what processor to use for image manipulation.
     */
    private static function determineProcessor(): void
    {
        // Determine what processor to use
        if (self::$processor === null) {
            // Look for imagemagick first since it can handle more than GD
            try {
                // If exec is disabled we can't use imagemagick
                if (in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
                    throw new Exception();
                }

                if (fCore::checkOS('windows')) {
                    $win_search = 'dir /B "C:\Program Files\ImageMagick*" 2> NUL';
                    exec($win_search, $win_output);
                    $win_output = trim(implode("\n", $win_output));

                    if (! $win_output || stripos($win_output, 'File not found') !== false) {
                        throw new Exception();
                    }

                    $path = 'C:\\Program Files\\'.$win_output.'\\';
                } elseif (fCore::checkOS('linux', 'bsd', 'solaris', 'osx')) {
                    $found = false;

                    if (fCore::checkOS('solaris')) {
                        $locations = [
                            '/opt/local/bin/',
                            '/opt/bin/',
                            '/opt/csw/bin/',
                        ];
                    } else {
                        $locations = [
                            '/usr/local/bin/',
                            '/usr/bin/',
                        ];
                    }

                    foreach ($locations as $location) {
                        if (self::isSafeModeExecDirRestricted($location)) {
                            continue;
                        }
                        if (self::isOpenBaseDirRestricted($location)) {
                            exec($location.'convert -version', $output);
                            if ($output) {
                                $found = true;
                                $path = $location;

                                break;
                            }
                        } elseif (is_executable($location.'convert')) {
                            $found = true;
                            $path = $location;

                            break;
                        }
                    }

                    // We have no fallback in solaris
                    if (! $found && fCore::checkOS('solaris')) {
                        throw new Exception();
                    }

                    // On linux and bsd can try whereis
                    if (! $found && fCore::checkOS('linux', 'freebsd')) {
                        $nix_search = 'whereis -b convert';
                        exec($nix_search, $nix_output);
                        $nix_output = trim(str_replace('convert:', '', implode("\n", $nix_output)));

                        if (! $nix_output) {
                            throw new Exception();
                        }

                        $path = preg_replace('#^(.*)convert$#i', '\1', $nix_output);
                    }

                    // OSX has a different whereis command
                    if (! $found && fCore::checkOS('osx', 'netbsd', 'openbsd')) {
                        $osx_search = 'whereis convert';
                        exec($osx_search, $osx_output);
                        $osx_output = trim(implode("\n", $osx_output));

                        if (! $osx_output) {
                            throw new Exception();
                        }

                        if (preg_match('#^(.*)convert#i', $osx_output, $matches)) {
                            $path = $matches[1];
                        }
                    }
                } else {
                    $path = null;
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
     * Gets the image type from a file by looking at the file contents.
     *
     * @param string $image The image path to get the type for
     *
     * @return null|string The type of the image - `'jpg'`, `'gif'`, `'png'` or `'tif'` - null if not one of those
     */
    private static function getImageType($image)
    {
        // if (function_exists('exif_imagetype')) {
        //  fCore::startErrorCapture();
        //  $type = exif_imagetype(static::getFlysystem()->getObjectUrl($image));
        //  fCore::stopErrorCapture();
        //
        //  if ($type === IMAGETYPE_JPEG) {
        //      return 'jpg';
        //  }
        //
        //  if ($type === IMAGETYPE_TIFF_II || $type === IMAGETYPE_TIFF_MM) {
        //      return 'tif';
        //  }
        //
        //  if ($type === IMAGETYPE_PNG) {
        //      return 'png';
        //  }
        //
        //  if ($type === IMAGETYPE_GIF) {
        //      return 'gif';
        //  }
        // }

        // Fall back to legacy image detection.
        $handle = static::getFlysystem()->readStream($image);
        $contents = fread($handle, 12);
        fclose($handle);

        $_0_8 = substr($contents, 0, 8);
        $_0_4 = substr($contents, 0, 4);
        $_6_4 = substr($contents, 6, 4);
        $_20_4 = substr($contents, 20, 4);

        if ($_0_4 == "MM\x00\x2A" || $_0_4 == "II\x2A\x00") {
            return 'tif';
        }

        if ($_0_8 == "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'png';
        }

        if ($_0_4 == 'GIF8') {
            return 'gif';
        }

        if ($_6_4 == 'JFIF' || $_6_4 == 'Exif' || ($_0_4 == "\xFF\xD8\xFF\xED" && $_20_4 == '8BIM')) {
            return 'jpg';
        }
    }

    /**
     * Checks if the path specified is restricted by open basedir.
     *
     * @param string $path The path to check
     *
     * @return bool If the path is restricted by the `open_basedir` ini setting
     */
    private static function isOpenBaseDirRestricted($path)
    {
        if (ini_get('open_basedir')) {
            $open_basedirs = explode((fCore::checkOS('windows')) ? ';' : ':', ini_get('open_basedir'));
            $found = false;

            foreach ($open_basedirs as $open_basedir) {
                if (strpos($path, $open_basedir) === 0) {
                    $found = true;
                }
            }

            if (! $found) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the path specified is restricted by the safe mode exec dir restriction.
     *
     * @param string $path The path to check
     *
     * @return bool If the path is restricted by the `safe_mode_exec_dir` ini setting
     */
    private static function isSafeModeExecDirRestricted($path)
    {
        if (! in_array(strtolower(ini_get('safe_mode')), ['0', '', 'off'])) {
            $exec_dir = ini_get('safe_mode_exec_dir');
            if (! $exec_dir || stripos($path, $exec_dir) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gets the dimensions of the image as of the last modification.
     *
     * @return array An associative array: `'width' => {integer}, 'height' => {integer}`
     */
    private function getCurrentDimensions()
    {
        if (empty($this->pending_modifications)) {
            $output = self::getInfo($this->file);
            unset($output['type']);
        } else {
            $last_modification = $this->pending_modifications[count($this->pending_modifications) - 1];
            $output['width'] = $last_modification['width'];
            $output['height'] = $last_modification['height'];
        }

        return $output;
    }

    /**
     * Processes the current image using GD.
     *
     * @param string $output_file  The file to save the image to
     * @param int    $jpeg_quality The JPEG quality to use
     */
    private function processWithGD(fImage $temp_file, $jpeg_quality): void
    {
        $type = self::getImageType($temp_file);
        $save_alpha = false;

        $path_info = fFilesystem::getPathInfo($temp_file);
        $new_type = $path_info['extension'];
        $new_type = ($type == 'jpeg') ? 'jpg' : $type;

        if (! in_array($new_type, ['gif', 'jpg', 'png'])) {
            $new_type = $type;
        }

        switch ($type) {
            case 'gif':
                $gd_res = imagecreatefromgif($temp_file);
                $save_alpha = true;

                break;

            case 'jpg':
                $gd_res = imagecreatefromjpeg($temp_file);

                break;

            case 'png':
                $gd_res = imagecreatefrompng($temp_file);
                $save_alpha = true;

                break;
        }

        foreach ($this->pending_modifications as $mod) {
            $new_gd_res = imagecreatetruecolor($mod['width'], $mod['height']);
            if ($save_alpha) {
                imagealphablending($new_gd_res, false);
                imagesavealpha($new_gd_res, true);
                if ($new_type == 'gif') {
                    $transparent = imagecolorallocatealpha($new_gd_res, 255, 255, 255, 127);
                    imagefilledrectangle($new_gd_res, 0, 0, $mod['width'], $mod['height'], $transparent);
                    imagecolortransparent($new_gd_res, $transparent);
                }
            }

            // Perform the resize operation
            if ($mod['operation'] == 'resize') {
                imagecopyresampled(
                    $new_gd_res,
                    $gd_res,
                    0,
                    0,
                    0,
                    0,
                    $mod['width'],
                    $mod['height'],
                    $mod['old_width'],
                    $mod['old_height']
                );

            // Perform the crop operation
            } elseif ($mod['operation'] == 'crop') {
                imagecopyresampled(
                    $new_gd_res,
                    $gd_res,
                    0,
                    0,
                    $mod['start_x'],
                    $mod['start_y'],
                    $mod['width'],
                    $mod['height'],
                    $mod['width'],
                    $mod['height']
                );

            // Perform the desaturate operation
            } elseif ($mod['operation'] == 'desaturate') {
                // Create a palette of grays
                $grays = [];
                for ($i = 0; $i < 256; $i++) {
                    $grays[$i] = imagecolorallocate($new_gd_res, $i, $i, $i);
                }
                $transparent = imagecolorallocatealpha($new_gd_res, 255, 255, 255, 127);

                // Loop through every pixel and convert the rgb values to grays
                for ($x = 0; $x < $mod['width']; $x++) {
                    for ($y = 0; $y < $mod['height']; $y++) {
                        $color = imagecolorat($gd_res, $x, $y);
                        if ($type != 'gif') {
                            $red = ($color >> 16) & 0xFF;
                            $green = ($color >> 8) & 0xFF;
                            $blue = $color & 0xFF;
                            if ($save_alpha) {
                                $alpha = ($color >> 24) & 0x7F;
                            }
                        } else {
                            $color_info = imagecolorsforindex($gd_res, $color);
                            $red = $color_info['red'];
                            $green = $color_info['green'];
                            $blue = $color_info['blue'];
                            $alpha = $color_info['alpha'];
                        }

                        if (! $save_alpha || $alpha != 127) {
                            // Get the appropriate gray (http://en.wikipedia.org/wiki/YIQ)
                            $yiq = round(($red * 0.299) + ($green * 0.587) + ($blue * 0.114));

                            if (! $save_alpha || $alpha == 0) {
                                $new_color = $grays[$yiq];
                            } else {
                                $new_color = imagecolorallocatealpha($new_gd_res, $yiq, $yiq, $yiq, $alpha);
                            }
                        } else {
                            $new_color = $transparent;
                        }

                        imagesetpixel($new_gd_res, $x, $y, $new_color);
                    }
                }
            }

            imagedestroy($gd_res);

            $gd_res = $new_gd_res;
        }

        // Save the file
        switch ($new_type) {
            case 'gif':
                imagetruecolortopalette($gd_res, true, 256);
                imagegif($gd_res, $temp_file->getPath());

                break;

            case 'jpg':
                imagejpeg($gd_res, $temp_file->getPath(), $jpeg_quality);

                break;

            case 'png':
                imagepng($gd_res, $temp_file->getPath());

                break;
        }

        $this->write(fopen($gd_res, 'r'));
        imagedestroy($gd_res);
    }

    /**
     * Processes the current image using ImageMagick.
     *
     * @param string $output_file  The file to save the image to
     * @param int    $jpeg_quality The JPEG quality to use
     * @param mixed  $filename
     */
    private function processWithImageMagick($filename, fImage $temp_file, $jpeg_quality): void
    {
        $type = fImage::getImageType($temp_file->getPath());

        if (fCore::checkOS('windows')) {
            $convert_command = str_replace(' ', '" "', self::$imagemagick_dir.'convert.exe');
        } else {
            $convert_command = escapeshellarg(self::$imagemagick_dir.'convert');
        }

        if (self::$imagemagick_temp_dir) {
            $convert_command .= ' -set registry:temporary-path '.escapeshellarg(self::$imagemagick_temp_dir).' ';
        }

        // Determining in what format the file is going to be saved
        $info = pathinfo($filename);
        $new_type = $info['extension'];
        // Normalize jpeg extension to jpg
        if ($new_type === 'jpeg') {
            $new_type = 'jpg';
        }

        if (! in_array($new_type, ['gif', 'jpg', 'png'])) {
            $new_type = $type;
        }

        $file = $temp_file->getPath();
        if ($type != 'gif' || $new_type != 'gif') {
            $file .= '[0]';
        }

        $convert_command .= ' '.escapeshellarg($file).' ';

        // Animated gifs need to be coalesced
        if ($temp_file->isAnimatedGif()) {
            $convert_command .= ' -coalesce ';
        }

        // TIFF files should be set to a depth of 8
        if ($type == 'tif') {
            $convert_command .= ' -depth 8 ';
        }

        foreach ($this->pending_modifications as $mod) {
            // Perform the resize operation
            if ($mod['operation'] == 'resize') {
                $convert_command .= ' -resize "'.$mod['width'].'x'.$mod['height'];
                if ($mod['old_width'] < $mod['width'] || $mod['old_height'] < $mod['height']) {
                    $convert_command .= '<';
                }
                $convert_command .= '" ';

            // Perform the crop operation
            } elseif ($mod['operation'] == 'crop') {
                $convert_command .= ' -crop '.$mod['width'].'x'.$mod['height'];
                $convert_command .= '+'.$mod['start_x'].'+'.$mod['start_y'];
                $convert_command .= ' -repage '.$mod['width'].'x'.$mod['height'].'+0+0 ';

            // Perform the desaturate operation
            } elseif ($mod['operation'] == 'desaturate') {
                $convert_command .= ' -colorspace GRAY ';
            }
        }

        // Default to the RGB colorspace
        if (strpos($convert_command, ' -colorspace ') === false) {
            $convert_command .= ' -colorspace sRGB ';
        }

        if ($new_type == 'jpg') {
            $convert_command .= ' -compress JPEG -quality '.$jpeg_quality.' ';
        }
        $output_file = $temp_file->getParent().$info['basename'];
        $convert_command .= ' '.escapeshellarg($new_type.':'.$output_file);

        $result = exec($convert_command);

        $this->write(fopen($output_file, 'r'));
    }
}

/*
 * Copyright (c) 2007-2010 Will Bond <will@flourishlib.com>, others
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
