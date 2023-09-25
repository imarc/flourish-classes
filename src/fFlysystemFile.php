<?php

use Imarc\VAL\Traits\Flourish\hasFlysystem;
use Imarc\VAL\Traits\Flourish\hasTempDir;

/**
 * Represents a file on the filesystem, also provides static file-related methods.
 */
class fFlysystemFile extends fFile
{
    use hasFlysystem;
    use hasTempDir;

    // The following constants allow for nice looking callbacks to static methods
    public const create = 'fFlysystemFile::create';

    /**
     * A backtrace from when the file was deleted.
     *
     * @var array
     */
    protected $deleted;

    /**
     * The full path to the file.
     *
     * @var string
     */
    protected $file;

    /**
     * The current line of the file.
     *
     * @var string
     */
    private $current_line;

    /**
     * The current line number of the file.
     *
     * @var string
     */
    private $current_line_number;

    /**
     * The file handle for iteration.
     *
     * @var resource
     */
    private $file_handle;

    /**
     * Creates an object to represent a file on the filesystem.
     *
     * If multiple fFile objects are created for a single file, they will
     * reflect changes in each other including rename and delete actions.
     *
     * @param string $file        The path to the file
     * @param bool   $skip_checks If file checks should be skipped, which improves performance, but may cause undefined behavior - only skip these if they are duplicated elsewhere
     *
     * @throws fValidationException When no file was specified, the file does not exist or the path specified is not a file
     *
     * @return fFile
     */
    public function __construct($file, $skip_checks = false)
    {
        if (! $this->getFlysystem()) {
            throw new fProgrammerException('Flysystem has not been initialized yet');
        }

        $flysystem = $this->getFlysystem();

        if (! $skip_checks) {
            if (! $flysystem->has($file)) {
                throw new fValidationException(
                    'The file specified, %s, does not exist or is not readable',
                    $file
                );
            }

            $contents = $flysystem->getMetadata($file);

            if ($contents['type'] === 'dir') {
                throw new fValidationException(
                    'The file specified, %s, is actually a directory',
                    $file
                );
            }
        }

        $this->file = &fFlysystem::hookFilenameMap($file);
        $this->deleted = &fFlysystem::hookDeletedMap($file);

        // If the file is listed as deleted and were not inside a transaction,
        // but we've gotten to here, then the file exists, so we can wipe the backtrace
        if ($this->deleted !== null && ! fFlysystem::isInsideTransaction()) {
            fFlysystem::updateDeletedMap($file, null);
        }
    }

    /**
     * Duplicates a file in the current directory when the object is cloned.
     *
     * @internal
     *
     * @return void
     */
    public function __clone()
    {
        $this->tossIfDeleted();

        $directory = $this->getParent();
        $file = fFlysystem::makeUniqueName($directory->getPath().$this->getName());

        $this->getFlysystem()->copy($this->getPath(), $file);

        $this->file = &fFlysystem::hookFilenameMap($file);
        $this->deleted = &fFlysystem::hookDeletedMap($file);

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::recordDuplicate($this);
        }
    }

    /**
     * All requests that hit this method should be requests for callbacks.
     *
     * @internal
     *
     * @param string $method The method to create a callback for
     *
     * @return callable The callback for the method requested
     */
    public function __get($method)
    {
        return [$this, $method];
    }

    /**
     * The iterator information doesn't need to be serialized since a resource can't be.
     *
     * @internal
     *
     * @return array The instance variables to serialize
     */
    public function __sleep()
    {
        return ['deleted', 'file'];
    }

    /**
     * Returns the filename of the file.
     *
     * @return string The filename
     */
    public function __toString()
    {
        try {
            return $this->getName();
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Re-inserts the file back into the filesystem map when unserialized.
     *
     * @internal
     */
    public function __wakeup()
    {
        $file = $this->file;
        $deleted = $this->deleted;

        $this->file = &fFlysystem::hookFilenameMap($file);
        $this->deleted = &fFlysystem::hookDeletedMap($file);

        if ($deleted !== null) {
            fFlysystem::updateDeletedMap($file, $deleted);
        }
    }

    /**
     * Creates a file on the filesystem and returns an object representing it.
     *
     * This operation will be reverted by a filesystem transaction being rolled back.
     *
     * @param string          $path The path to the new file
     * @param resource|string $file A resource stream or a string to a path to be opened as a resource
     *
     * @throws fValidationException When no file was specified or the file already exists
     *
     * @return fFile
     */
    public static function create($path, $file)
    {
        $flysystem = static::getFlysystem();

        if ($flysystem->has($path)) {
            throw new fValidationException(
                'The file specified, %s, already exists',
                $path
            );
        }

        if (! is_resource($file)) {
            $file = fopen($file, 'r');
        }

        $flysystem->writeStream($path, $file);

        if (is_resource($file)) {
            fclose($file);
        }

        $file = new self($path);

        // this used to be wrapped in a check for fFlysystem::isInsideTransaction()
        // which seemed wrong?
        fFlysystem::recordCreate($file);

        return $file;
    }

    /**
     * Determines the file's mime type by either looking at the file contents or matching the extension.
     *
     * Please see the ::getMimeType() description for details about how the
     * mime type is determined and what mime types are detected.
     *
     * @internal
     *
     * @param string $file     The file to check the mime type for - must be a valid filesystem path if no `$contents` are provided, otherwise just a filename
     * @param string $contents The first 4096 bytes of the file content - the `$file` parameter only need be a filename if this is provided
     *
     * @return false|string The mime type of the file
     */
    public static function determineMimeType($file, $contents = null): string|false
    {
        $flysystem = static::getFlysystem();

        if (! $flysystem->has($file)) {
            throw new fValidationException(
                'The file specified, %s, does not exist',
                $file
            );
        }

        return $flysystem->getMimetype($file);
    }

    /**
     * Appends the provided data to the file.
     *
     * If a filesystem transaction is in progress and is rolled back, this
     * data will be removed.
     *
     * @param mixed $data The data to append to the file
     *
     * @return fFile The file object, to allow for method chaining
     */
    public function append($data)
    {
        $this->tossIfDeleted();

        if (! $this->getFlysystem()->has($this->getPath())) {
            throw new fEnvironmentException(
                'This file, %s, can not be appended because it does not exist',
                $this->file
            );
        }

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::recordAppend($this, $data);
        }

        $stream = $this->getFlysystem()->readStream($this->getPath());

        fseek($stream, -1);
        fwrite($stream, $data);

        $this->getFlysystem()->updateStream($this->getPath(), $stream);

        return $this;
    }

    /**
     * Returns the current line of the file (required by iterator interface).
     *
     * @throws fNoRemainingException When there are no remaining lines in the file
     *
     * @internal
     *
     * @return string The current row
     */
    public function current(): string
    {
        $this->tossIfDeleted();

        // Primes the result set
        if ($this->file_handle === null) {
            $this->next();
        } elseif (! $this->valid()) {
            throw new fNoRemainingException('There are no remaining lines');
        }

        return $this->current_line;
    }

    /**
     * Deletes the current file.
     *
     * This operation will NOT be performed until the filesystem transaction
     * has been committed, if a transaction is in progress. Any non-Flourish
     * code (PHP or system) will still see this file as existing until that
     * point.
     */
    public function delete()
    {
        if ($this->deleted) {
            return;
        }

        if (! $this->getFlysystem()->has($this->getPath())) {
            throw new fEnvironmentException(
                'This file, %s, can not be deleted because it does not exist',
                $this->file
            );
        }

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            return fFlysystem::recordDelete($this);
        }

        $this->getFlysystem()->delete($this->getPath());

        fFlysystem::updateDeletedMap($this->file, debug_backtrace());
        fFlysystem::updateFilenameMap($this->file, '*DELETED at '.time().' with token '.uniqid('', true).'* '.$this->file);
    }

    /**
     * Creates a new file object with a copy of this file.
     *
     * If no directory is specified, the file is created with a new name in
     * the current directory. If a new directory is specified, you must also
     * indicate if you wish to overwrite an existing file with the same name
     * in the new directory or create a unique name.
     *
     * This operation will be reverted by a filesystem transaction being rolled
     * back.
     *
     * @param string     $new_directory The directory to duplicate the file into if different than the current directory
     * @param bool       $overwrite     if a new directory is specified, this indicates if a file with the same name should be overwritten
     * @param null|mixed $to
     *
     * @return fFile The new fFile object
     */
    public function duplicate($to = null, $overwrite = false)
    {
        $this->tossIfDeleted();

        if ($to === null) {
            $to = $this->getParent();
        }

        if (! is_object($to)) {
            $to = new fFlysystemDirectory($to);
        }

        $new_filename = $to->getPath().$this->getName();
        if ($this->exists($new_filename)) {
            if (! $overwrite) {
                $new_filename = fFlysystem::makeUniqueName($new_filename);
            }
        }

        if ($overwrite) {
            $this->getFlysystem()->putStream(
                $new_filename,
                $this->getFlysystem()->readStream($this->getPath())
            );
        } else {
            $this->getFlysystem()->copy($this->getPath(), $new_filename);
        }

        $class = get_class($this);
        $file = new $class($new_filename);

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::recordDuplicate($file);
        }

        return $file;
    }

    /**
     * Gets the file extension.
     *
     * @return array|string The extension of the file
     */
    public function getExtension(): array|string
    {
        return fFlysystem::getPathInfo($this->file, 'extension');
    }

    /**
     * Gets the file's mime type.
     *
     * This method will attempt to look at the file contents and the file
     * extension to determine the mime type. If the file contains binary
     * information, the contents will be used for mime type verification,
     * however if the contents appear to be plain text, the file extension
     * will be used.
     *
     * The following mime types are supported. All other binary file types
     * will be returned as `application/octet-stream` and all other text files
     * will be returned as `text/plain`.
     *
     * **Archive:**
     *
     *  - `application/x-bzip2` BZip2 file
     *  - `application/x-compress` Compress (*nix) file
     *  - `application/x-gzip` GZip file
     *  - `application/x-rar-compressed` Rar file
     *  - `application/x-stuffit` StuffIt file
     *  - `application/x-tar` Tar file
     *  - `application/zip` Zip file
     *
     * **Audio:**
     *
     *  - `audio/x-flac` FLAC audio
     *  - `audio/mpeg` MP3 audio
     *  - `audio/mp4` MP4 (AAC) audio
     *  - `audio/vorbis` Ogg Vorbis audio
     *  - `audio/x-wav` WAV audio
     *  - `audio/x-ms-wma` Windows media audio
     *
     * **Document:**
     *
     *  - `application/vnd.ms-excel` Excel (2000, 2003 and 2007) file
     *  - `application/pdf` PDF file
     *  - `application/vnd.ms-powerpoint` Powerpoint (2000, 2003, 2007) file
     *  - `text/rtf` RTF file
     *  - `application/msword` Word (2000, 2003 and 2007) file
     *
     * **Image:**
     *
     *  - `image/x-ms-bmp` BMP file
     *  - `application/postscript` EPS file
     *  - `image/gif` GIF file
     *  - `application/vnd.microsoft.icon` ICO file
     *  - `image/jpeg` JPEG file
     *  - `image/png` PNG file
     *  - `image/tiff` TIFF file
     *  - `image/svg+xml` SVG file
     *
     * **Text:**
     *
     *  - `text/css` CSS file
     *  - `text/csv` CSV file
     *  - `text/html` (X)HTML file
     *  - `text/calendar` iCalendar file
     *  - `application/javascript` Javascript file
     *  - `application/x-perl` Perl file
     *  - `application/x-httpd-php` PHP file
     *  - `application/x-python` Python file
     *  - `application/rss+xml` RSS feed
     *  - `application/x-ruby` Ruby file
     *  - `text/tab-separated-values` TAB file
     *  - `text/x-vcard` VCard file
     *  - `application/xhtml+xml` XHTML (Real) file
     *  - `application/xml` XML file
     *
     * **Video/Animation:**
     *
     *  - `video/x-msvideo` AVI video
     *  - `application/x-shockwave-flash` Flash movie
     *  - `video/x-flv` Flash video
     *  - `video/x-ms-asf` Microsoft ASF video
     *  - `video/mp4` MP4 video
     *  - `video/ogg` OGM and Ogg Theora video
     *  - `video/quicktime` Quicktime video
     *  - `video/x-ms-wmv` Windows media video
     *
     * @return string The mime type of the file
     */
    public function getMimeType()
    {
        $this->tossIfDeleted();

        return self::determineMimeType($this->file);
    }

    /**
     * Returns the last modification time of the file.
     *
     * @return fTimestamp The timestamp of when the file was last modified
     */
    public function getMTime()
    {
        $this->tossIfDeleted();

        return new fTimestamp(filemtime($this->file));
    }

    /**
     * Gets the filename (i.e. does not include the directory).
     *
     * @return array|string The filename of the file
     */
    public function getName(): array|string
    {
        // For some reason PHP calls the filename the basename, where filename is the filename minus the extension
        return fFlysystem::getPathInfo($this->file, 'basename');
    }

    /**
     * Gets the directory the file is located in.
     *
     * @return fFlysystemDirectory The directory containing the file
     */
    public function getParent()
    {
        return new fFlysystemDirectory(fFlysystem::getPathInfo($this->file, 'dirname'));
    }

    /**
     * Gets the file's current path (directory and filename).
     *
     * If the web path is requested, uses translations set with
     * fFlysystem::addWebPathTranslation()
     *
     * @param bool $translate_to_web_path If the path should be the web path
     *
     * @return string The path (directory and filename) for the file
     */
    public function getPath($translate_to_web_path = false)
    {
        if ($translate_to_web_path) {
            return fFlysystem::translateToWebPath($this->file);
        }

        return $this->file;
    }

    /**
     * Gets the size of the file.
     *
     * The return value may be incorrect for files over 2GB on 32-bit OSes.
     *
     * @param bool $format         If the filesize should be formatted for human readability
     * @param int  $decimal_places The number of decimal places to format to (if enabled)
     *
     * @return int|string If formatted a string with filesize in b/kb/mb/gb/tb, otherwise an integer
     */
    public function getSize($format = false, $decimal_places = 1)
    {
        $this->tossIfDeleted();

        // This technique can overcome signed integer limit
        $size = sprintf('%u', filesize($this->file));

        if (! $format) {
            return $size;
        }

        return fFlysystem::formatFilesize($size, $decimal_places);
    }

    /**
     * Check to see if the current file is writable.
     *
     * @return bool If the file is writable
     */
    public function isWritable()
    {
        return true;
    }

    /**
     * Returns the current one-based line number (required by iterator interface).
     *
     * @throws fNoRemainingException When there are no remaining lines in the file
     *
     * @internal
     *
     * @return string The current line number
     */
    public function key(): string
    {
        $this->tossIfDeleted();

        if ($this->file_handle === null) {
            $this->next();
        } elseif (! $this->valid()) {
            throw new fNoRemainingException('There are no remaining lines');
        }

        return $this->current_line_number;
    }

    /**
     * Moves the current file to a different directory.
     *
     * Please note that ::rename() will rename a file in its directory or rename
     * it into a different directory.
     *
     * If the current file's filename already exists in the new directory and
     * the overwrite flag is set to false, the filename will be changed to a
     * unique name.
     *
     * This operation will be reverted if a filesystem transaction is in
     * progress and is later rolled back.
     *
     * @param fFlysystemDirectory|string $new_directory The directory to move this file into
     * @param bool                       $overwrite     If the current filename already exists in the new directory, `TRUE` will cause the file to be overwritten, `FALSE` will cause the new filename to change
     *
     * @throws fValidationException When the directory passed is not a directory or is not readable
     *
     * @return fFile The file object, to allow for method chaining
     */
    public function move($new_directory, $overwrite)
    {
        if (! $new_directory instanceof fFlysystemDirectory) {
            $new_directory = new fFlysystemDirectory($new_directory);
        }

        return $this->rename($new_directory->getPath().$this->getName(), $overwrite);
    }

    /**
     * Advances to the next line in the file (required by iterator interface).
     *
     * @throws fNoRemainingException When there are no remaining lines in the file
     *
     * @internal
     */
    public function next(): void
    {
        $this->tossIfDeleted();

        if ($this->file_handle === null) {
            $this->file_handle = $this->getFlysystem()->readStream($this->getPath());
            $this->current_line = '';
            $this->current_line_number = 0;
        } elseif (! $this->valid()) {
            throw new fNoRemainingException('There are no remaining lines');
        }

        $this->current_line = fgets($this->file_handle);
        $this->current_line_number++;
    }

    /**
     * Prints the contents of the file.
     *
     * This method is primarily intended for when PHP is used to control access
     * to files.
     *
     * Be sure to turn off output buffering and close the session, if open, to
     * prevent performance issues.
     *
     * @param bool  $headers  If HTTP headers for the file should be included
     * @param mixed $filename Present the file as an attachment instead of just outputting type headers - if a string is passed, that will be used for the filename, if `TRUE` is passed, the current filename will be used
     *
     * @return fFile The file object, to allow for method chaining
     */
    public function output($headers, $filename = null)
    {
        $this->tossIfDeleted();
        $metadata = $this->getFlysystem()->getMetadata($this->getPath());

        if (ob_get_level() > 0) {
            throw new fProgrammerException(
                'The method requested, %1$s, can not be used when output buffering is turned on, due to potential memory issues. Please call %2$s, %3$s and %4$s, or %5$s as appropriate to turn off output buffering.',
                'output()',
                'ob_end_clean()',
                'fBuffer::erase()',
                'fBuffer::stop()',
                'fTemplating::destroy()'
            );
        }

        if ($headers) {
            if ($filename !== null) {
                if ($filename === true) {
                    $filename = $this->getName();
                }
                header('Content-Disposition: attachment; filename="'.$filename.'"');
            }
            header('Cache-Control: ');
            header('Content-Length: '.$contents['size']);
            header('Content-Type: '.$contents['mime-type']);
            header('Expires: ');
            header('Last-Modified: '.$this->getMTime()->format('D, d M Y H:i:s'));
            header('Pragma: ');
        }

        $this->getFlysystem()->read($this->getPath());

        return $this;
    }

    /**
     * Reads the data from the file.
     *
     * Reads all file data into memory, use with caution on large files!
     *
     * This operation will read the data that has been written during the
     * current transaction if one is in progress.
     *
     * @param mixed $data The data to write to the file
     *
     * @return false|string The contents of the file
     */
    public function read(): string|false
    {
        $this->tossIfDeleted();

        return $this->getFlysystem()->read($this->getPath());
    }

    /**
     * Stream the data from the file.
     *
     * This operation will read the data that has been written during the
     * current transaction if one is in progress.
     *
     * @return false|resource The stream
     */
    public function stream()
    {
        $this->tossIfDeleted();

        return $this->getFlysystem()->readStream($this->getPath());
    }

    /**
     * Renames the current file.
     *
     * If the filename already exists and the overwrite flag is set to false,
     * a new filename will be created.
     *
     * This operation will be reverted if a filesystem transaction is in
     * progress and is later rolled back.
     *
     * @param string $new_filename The new full path to the file or a new filename in the current directory
     * @param bool   $overwrite    If the new filename already exists, `TRUE` will cause the file to be overwritten, `FALSE` will cause the new filename to change
     *
     * @return fFile The file object, to allow for method chaining
     */
    public function rename($new_filename, $overwrite)
    {
        $this->tossIfDeleted();

        if (! $this->getFlysystem()->has($this->getPath())) {
            throw new fEnvironmentException(
                'The file, %s, can not be renamed because it does not exist',
                $this->file
            );
        }

        // If the filename does not contain any folder traversal, rename the file in the current directory
        if (preg_match('#^[^/\\\\]+$#D', $new_filename)) {
            $new_filename = $this->getParent()->getPath().$new_filename;
        }

        $info = fFlysystem::getPathInfo($new_filename);

        // Make the filename absolute
        $new_filename = fFlysystemDirectory::makeCanonical($info['dirname']).$info['basename'];

        if ($this->file == $new_filename && $overwrite) {
            return $this;
        }

        if ($this->getFlysystem()->has($new_filename) && ! $overwrite) {
            $new_filename = fFlysystem::makeUniqueName($new_filename);
        }

        if ($this->getFlysystem()->has($new_filename)) {
            if ($this->getFlysystem()->getVisibility($new_filename) === 'private') {
                throw new fEnvironmentException(
                    'The new filename specified, %s, already exists, but is not writable',
                    $new_filename
                );
            }

            if (fFlysystem::isInsideTransaction()) {
                fFlysystem::recordWrite(new self($new_filename));
            }
        }

        $this->getFlysystem()->rename($this->getPath(), $new_filename);

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::recordRename($this->file, $new_filename);
        }

        fFlysystem::updateFilenameMap($this->file, $new_filename);

        return $this;
    }

    /**
     * Rewinds the file handle (required by iterator interface).
     *
     * @internal
     */
    public function rewind(): void
    {
        $this->tossIfDeleted();

        if ($this->file_handle !== null) {
            rewind($this->file_handle);
        }
    }

    /**
     * Returns if the file has any lines left (required by iterator interface).
     *
     * @internal
     *
     * @return bool If the iterator is still valid
     */
    public function valid(): bool
    {
        $this->tossIfDeleted();

        if ($this->file_handle === null) {
            return true;
        }

        return $this->current_line !== false;
    }

    /**
     * Writes the provided data to the file.
     *
     * Requires all previous data to be stored in memory if inside a
     * transaction, use with caution on large files!
     *
     * If a filesystem transaction is in progress and is rolled back, the
     * previous data will be restored.
     *
     * @param mixed $data The data to write to the file
     *
     * @return fFile The file object, to allow for method chaining
     */
    public function write($data)
    {
        $this->tossIfDeleted();

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::recordWrite($this);
        }

        if (! is_resource($data)) {
            $contents = fopen('php://temp', 'r+');
            fwrite($contents, $data);
        } else {
            $contents = $data;
        }

        $this->getFlysystem()->putStream($this->getPath(), $contents);

        return $this;
    }

    public function getObjectUrl($use_public = false)
    {
        return $this->getFlysystem()->getObjectUrl($this->getPath(), $use_public);
    }

    /**
     * @param null|string $path
     */
    public static function exists(string|null $path = null)
    {
        if (isset($this)) {
            $path ??= $this->getPath();

            return $this->getFlysystem()->has($path);
        }

        return static::getFlysystem()->has($path);
    }

    /**
     * Throws an fProgrammerException if the file has been deleted.
     *
     * @return void
     */
    protected function tossIfDeleted()
    {
        if ($this->deleted) {
            throw new fProgrammerException(
                "The action requested can not be performed because the file has been deleted\n\nBacktrace for fFile::delete() call:\n%s",
                fCore::backtrace(0, $this->deleted)
            );
        }
    }

    /**
     * Looks for specific bytes in a file to determine the mime type of the file.
     *
     * @param string $content   The first 4 bytes of the file content to use for byte checking
     * @param string $extension The extension of the filetype, only used for difficult files such as Microsoft office documents
     *
     * @return string The mime type of the file
     */
    private static function determineMimeTypeByContents($content, $extension)
    {
        $length = strlen($content);
        $_0_8 = substr($content, 0, 8);
        $_0_6 = substr($content, 0, 6);
        $_0_5 = substr($content, 0, 5);
        $_0_4 = substr($content, 0, 4);
        $_0_3 = substr($content, 0, 3);
        $_0_2 = substr($content, 0, 2);
        $_8_4 = substr($content, 8, 4);

        // Images
        if ($_0_4 == "MM\x00\x2A" || $_0_4 == "II\x2A\x00") {
            return 'image/tiff';
        }

        if ($_0_8 == "\x89PNG\x0D\x0A\x1A\x0A") {
            return 'image/png';
        }

        if ($_0_4 == 'GIF8') {
            return 'image/gif';
        }

        if ($_0_2 == 'BM' && strlen($content) > 14 && [$content[14], ["\x0C", "\x28", "\x40", "\x80"]]) {
            return 'image/x-ms-bmp';
        }

        $normal_jpeg = $length > 10 && in_array(substr($content, 6, 4), ['JFIF', 'Exif']);
        $photoshop_jpeg = $length > 24 && $_0_4 == "\xFF\xD8\xFF\xED" && substr($content, 20, 4) == '8BIM';
        if ($normal_jpeg || $photoshop_jpeg) {
            return 'image/jpeg';
        }

        if (preg_match('#^[^\n\r]*\%\!PS-Adobe-3#', $content)) {
            return 'application/postscript';
        }

        if ($_0_4 == "\x00\x00\x01\x00") {
            return 'application/vnd.microsoft.icon';
        }

        // Audio/Video
        if ($_0_4 == 'MOVI') {
            if (in_array($_4_4, ['moov', 'mdat'])) {
                return 'video/quicktime';
            }
        }

        if ($length > 8 && substr($content, 4, 4) == 'ftyp') {
            $_8_3 = substr($content, 8, 3);
            $_8_2 = substr($content, 8, 2);

            if (in_array($_8_4, ['isom', 'iso2', 'mp41', 'mp42'])) {
                return 'video/mp4';
            }

            if ($_8_3 == 'M4A') {
                return 'audio/mp4';
            }

            if ($_8_3 == 'M4V') {
                return 'video/mp4';
            }

            if ($_8_3 == 'M4P' || $_8_3 == 'M4B' || $_8_2 == 'qt') {
                return 'video/quicktime';
            }
        }

        // MP3
        if (($_0_2 & "\xFF\xF6") == "\xFF\xF2") {
            if (($content[2] & "\xF0") != "\xF0" && ($content[2] & "\x0C") != "\x0C") {
                return 'audio/mpeg';
            }
        }
        if ($_0_3 == 'ID3') {
            return 'audio/mpeg';
        }

        if ($_0_8 == "\x30\x26\xB2\x75\x8E\x66\xCF\x11") {
            if ($content[24] == "\x07") {
                return 'audio/x-ms-wma';
            }
            if ($content[24] == "\x08") {
                return 'video/x-ms-wmv';
            }

            return 'video/x-ms-asf';
        }

        if ($_0_4 == 'RIFF' && $_8_4 == 'AVI ') {
            return 'video/x-msvideo';
        }

        if ($_0_4 == 'RIFF' && $_8_4 == 'WAVE') {
            return 'audio/x-wav';
        }

        if ($_0_4 == 'OggS') {
            $_28_5 = substr($content, 28, 5);
            if ($_28_5 == "\x01\x76\x6F\x72\x62") {
                return 'audio/vorbis';
            }
            if ($_28_5 == "\x07\x46\x4C\x41\x43") {
                return 'audio/x-flac';
            }
            // Theora and OGM
            if ($_28_5 == "\x80\x74\x68\x65\x6F" || $_28_5 == "\x76\x69\x64\x65") {
                return 'video/ogg';
            }
        }

        if ($_0_3 == 'FWS' || $_0_3 == 'CWS') {
            return 'application/x-shockwave-flash';
        }

        if ($_0_3 == 'FLV') {
            return 'video/x-flv';
        }

        // Documents
        if ($_0_5 == '%PDF-') {
            return 'application/pdf';
        }

        if ($_0_5 == '{\rtf') {
            return 'text/rtf';
        }

        // Office '97-2003 or Office 2007 formats
        if ($_0_8 == "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" || $_0_8 == "PK\x03\x04\x14\x00\x06\x00") {
            if (in_array($extension, ['xlsx', 'xls', 'csv', 'tab'])) {
                return 'application/vnd.ms-excel';
            }
            if (in_array($extension, ['pptx', 'ppt'])) {
                return 'application/vnd.ms-powerpoint';
            }
            // We default to word since we need something if the extension isn't recognized
            return 'application/msword';
        }

        if ($_0_8 == "\x09\x04\x06\x00\x00\x00\x10\x00") {
            return 'application/vnd.ms-excel';
        }

        if ($_0_6 == "\xDB\xA5\x2D\x00\x00\x00" || $_0_5 == "\x50\x4F\x5E\x51\x60" || $_0_4 == "\xFE\x37\x0\x23" || $_0_3 == "\x94\xA6\x2E") {
            return 'application/msword';
        }

        // Archives
        if ($_0_4 == "PK\x03\x04") {
            return 'application/zip';
        }

        if ($length > 257) {
            if (substr($content, 257, 6) == "ustar\x00") {
                return 'application/x-tar';
            }
            if (substr($content, 257, 8) == "ustar\x40\x40\x00") {
                return 'application/x-tar';
            }
        }

        if ($_0_4 == 'Rar!') {
            return 'application/x-rar-compressed';
        }

        if ($_0_2 == "\x1F\x9D") {
            return 'application/x-compress';
        }

        if ($_0_2 == "\x1F\x8B") {
            return 'application/x-gzip';
        }

        if ($_0_3 == 'BZh') {
            return 'application/x-bzip2';
        }

        if ($_0_4 == 'SIT!' || $_0_4 == 'SITD' || substr($content, 0, 7) == 'StuffIt') {
            return 'application/x-stuffit';
        }

        // Text files
        if (strpos($content, '<?xml') !== false) {
            if (stripos($content, '<!DOCTYPE') !== false) {
                return 'application/xhtml+xml';
            }
            if (strpos($content, '<svg') !== false) {
                return 'image/svg+xml';
            }
            if (strpos($content, '<rss') !== false) {
                return 'application/rss+xml';
            }

            return 'application/xml';
        }

        if (strpos($content, '<?php') !== false || strpos($content, '<?=') !== false) {
            return 'application/x-httpd-php';
        }

        if (preg_match('#^\#\![/a-z0-9]+(python|perl|php|ruby)$#mi', $content, $matches)) {
            switch (strtolower($matches[1])) {
                case 'php':
                    return 'application/x-httpd-php';

                case 'python':
                    return 'application/x-python';

                case 'perl':
                    return 'application/x-perl';

                case 'ruby':
                    return 'application/x-ruby';
            }
        }

        // Default
        return 'application/octet-stream';
    }

    /**
     * Uses the extension of the all-text file to determine the mime type.
     *
     * @param string $extension The file extension
     *
     * @return string The mime type of the file
     */
    private static function determineMimeTypeByExtension($extension)
    {
        switch ($extension) {
            case 'css':
                return 'text/css';

            case 'csv':
                return 'text/csv';

            case 'htm':
            case 'html':
            case 'xhtml':
                return 'text/html';

            case 'ics':
                return 'text/calendar';

            case 'js':
                return 'application/javascript';

            case 'php':
            case 'php3':
            case 'php4':
            case 'php5':
            case 'inc':
                return 'application/x-httpd-php';

            case 'pl':
            case 'cgi':
                return 'application/x-perl';

            case 'py':
                return 'application/x-python';

            case 'rb':
            case 'rhtml':
                return 'application/x-ruby';

            case 'rss':
                return 'application/rss+xml';

            case 'tab':
                return 'text/tab-separated-values';

            case 'vcf':
                return 'text/x-vcard';

            case 'xml':
                return 'application/xml';

            default:
                return 'text/plain';
        }
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
