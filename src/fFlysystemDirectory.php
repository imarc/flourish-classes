<?php

use Imarc\VAL\Traits\Flourish\hasFlysystem;

/**
 * Represents a directory on the filesystem, also provides static directory-related methods.
 */
class fFlysystemDirectory
{
    use hasFlysystem;

    // The following constants allow for nice looking callbacks to static methods
    public const create = 'fDirectory::create';

    public const makeCanonical = 'fDirectory::makeCanonical';

    /**
     * A backtrace from when the file was deleted.
     *
     * @var array
     */
    protected $deleted;

    /**
     * The full path to the directory.
     *
     * @var string
     */
    protected $directory;

    /**
     * Creates an object to represent a directory on the filesystem.
     *
     * If multiple fDirectory objects are created for a single directory,
     * they will reflect changes in each other including rename and delete
     * actions.
     *
     * @param string $directory   The path to the directory
     * @param bool   $skip_checks If file checks should be skipped, which improves performance, but may cause undefined behavior - only skip these if they are duplicated elsewhere
     * @param mixed  $create
     *
     * @throws fValidationException When no directory was specified, when the directory does not exist or when the path specified is not a directory
     *
     * @return fDirectory
     */
    public function __construct($directory, $create = false)
    {
        $directory = self::makeCanonical($directory);

        if ($create) {
            if ($this->exists($directory)) {
                throw new fValidationException(
                    'The directory specified, %s, already exists',
                    $directory
                );
            }

            $created = $this->getFlysystem()->createDir($directory);
        }

        $this->directory = &fFlysystem::hookFilenameMap($directory);
        $this->deleted = &fFlysystem::hookDeletedMap($directory);

        // If the directory is listed as deleted and we are not inside a transaction,
        // but we've gotten to here, then the directory exists, so we can wipe the backtrace
        if ($this->deleted !== null && ! fFlysystem::isInsideTransaction()) {
            fFlysystem::updateDeletedMap($directory, null);
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
     * Returns the full filesystem path for the directory.
     *
     * @return string The full filesystem path
     */
    public function __toString()
    {
        return $this->getPath();
    }

    /**
     * Creates a directory on the filesystem and returns an object representing it.
     *
     * The directory creation is done recursively, so if any of the parent
     * directories do not exist, they will be created.
     *
     * This operation will be reverted by a filesystem transaction being rolled back.
     *
     * @param string $directory The path to the new directory
     * @param bool   $visible   true = visisble, false = private
     *
     * @throws fValidationException When no directory was specified, or the directory already exists
     */
    public static function create($directory, $visible = true): static
    {
        $directory = new static($directory, true);

        if (! $visible) {
            $directory
                ->getFlysystem()
                ->setVisibility($directory->getPath(), 'private');
        }

        fFlysystem::recordCreate($directory);

        return $directory;
    }

    /**
     * Makes sure a directory has a `/` or `\` at the end.
     *
     * @param string $directory The directory to check
     *
     * @return string The directory name in canonical form
     */
    public static function makeCanonical($directory)
    {
        if (substr($directory, 0, 1) === '/') {
            $directory = substr($directory, 1);
        }

        if (substr($directory, -1) !== '/' && substr($directory, -1) !== '\\') {
            $directory .= DIRECTORY_SEPARATOR;
        }

        return $directory;
    }

    /**
     * Will delete a directory and all files and directories inside of it.
     *
     * This operation will not be performed until the filesystem transaction
     * has been committed, if a transaction is in progress. Any non-Flourish
     * code (PHP or system) will still see this directory and all contents as
     * existing until that point.
     */
    public function delete()
    {
        if ($this->deleted) {
            return;
        }

        $files = $this->scan();

        foreach ($files as $file) {
            $file->delete();
        }

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            return fFlysystem::delete($this);
        }

        $this->getFlysystem()->deleteDir($this->getPath());

        fFilesystem::updateDeletedMap($this->directory, debug_backtrace());
        fFilesystem::updateFilenameMapForDirectory($this->directory, '*DELETED at '.time().' with token '.uniqid('', true).'* '.$this->directory);
    }

    /**
     * Gets the name of the directory.
     *
     * @return array|string The name of the directory
     */
    public function getName(): array|string
    {
        return fFlysystem::getPathInfo($this->directory, 'basename');
    }

    /**
     * Gets the parent directory.
     *
     * @return self The object representing the parent directory
     */
    public function getParent(): self
    {
        $this->tossIfDeleted();

        $dirname = fFlysystem::getPathInfo($this->directory, 'dirname');

        if ($dirname == $this->directory) {
            throw new fEnvironmentException(
                'The current directory does not have a parent directory'
            );
        }

        return new self($dirname);
    }

    /**
     * Gets the directory's current path.
     *
     * If the web path is requested, uses translations set with
     * fFilesystem::addWebPathTranslation()
     *
     * @param bool $translate_to_web_path If the path should be the web path
     *
     * @return string The path for the directory
     */
    public function getPath($translate_to_web_path = false)
    {
        $this->tossIfDeleted();

        if ($translate_to_web_path) {
            return fFilesystem::translateToWebPath($this->directory);
        }

        return $this->directory;
    }

    /**
     * Gets the disk usage of the directory and all files and folders contained within.
     *
     * This method may return incorrect results if files over 2GB exist and the
     * server uses a 32 bit operating system
     *
     * @param bool  $format         If the filesize should be formatted for human readability
     * @param int   $decimal_places The number of decimal places to format to (if enabled)
     * @param mixed $recursive
     *
     * @return int|string If formatted, a string with filesize in b/kb/mb/gb/tb, otherwise an integer
     */
    public function getSize($format = false, $decimal_places = 1, $recursive = true)
    {
        $this->tossIfDeleted();

        $contents = $this->getFlysystem()
            ->listFiles($this->getPath(), $recursive);

        $size = 0;

        foreach ($contents as $content) {
            $fileSize = isset($content['size']) ? (int) $content['size'] : 0;
            $size += $fileSize;
        }

        return fFlysystem::formatFilesize($size, $decimal_places);
    }

    public function exists(string|null $directory = null): bool
    {
        if (! $directory) {
            $directory = $this->getPath();
        }

        return $this->getFlysystem()->has($directory);
    }

    /**
     * This should always be true as the Adapter must have write permissions
     * to storage.
     *
     * @return bool If the directory is writable
     */
    public function isWritable()
    {
        return true;
    }

    /**
     * Moves the current directory into a different directory.
     *
     * Please note that ::rename() will rename a directory in its current
     * parent directory or rename it into a different parent directory.
     *
     * If the current directory's name already exists in the new parent
     * directory and the overwrite flag is set to false, the name will be
     * changed to a unique name.
     *
     * This operation will be reverted if a filesystem transaction is in
     * progress and is later rolled back.
     *
     * @param fFlysystemDirectory|string $to        The directory to move this directory into
     * @param bool                       $overwrite If the current filename already exists in the new directory, `true` will cause the file to be overwritten, `false` will cause the new filename to change
     *
     * @throws fValidationException When the new parent directory passed is not a directory, is not readable or is a sub-directory of this directory
     */
    public function move($to, $overwrite = false): void
    {
        $this->tossIfDeleted();

        if (! $to instanceof self) {
            $to = new self($to);
        }

        if (strpos($to->getPath(), $this->getPath()) === 0) {
            throw new fValidationException('It is not possible to move a directory into one of its sub-directories');
        }

        $contents = $this->getFlysystem()->listFiles($this->getPath(), true);
        $toPath = $this->makeCanonical($to->getPath());
        $existingPath = $this->makeCanonical($this->getPath());

        foreach ($contents as $content) {
            $newPath = str_replace($this->getPath(), $to->getPath(), $content['path']);

            if (fFlysystem::isInsideTransaction()) {
                fFlysystem::rename($content['path'], $newPath);
            }

            fFilesystem::updateFilenameMap($content['path'], $newPath);

            if ($overwrite) {
                $this->getFlysystem()->forceRename($content['path'], $newPath);
            } else {
                try {
                    $this->getFlysystem()->rename($content['path'], $newPath);
                } catch (League\Flysystem\FileExistsException $e) {
                    throw new fValidationException($e->getMessage());
                }
            }
        }

        fFlysystem::updateFilenameMapForDirectory($this->getPath(), $to->getPath());

        $this->getFlysystem()->deleteDir($existingPath);
    }

    /**
     * Renames the current directory.
     *
     * This operation will NOT be performed until the filesystem transaction
     * has been committed, if a transaction is in progress. Any non-Flourish
     * code (PHP or system) will still see this directory (and all contained
     * files/dirs) as existing with the old paths until that point.
     *
     * @param string $new_dirname The new full path to the directory or a new name in the current parent directory
     * @param bool   $overwrite   If the new dirname already exists, true will cause the file to be overwritten, false will cause the new filename to change
     */
    public function rename($new_dirname, $overwrite): void
    {
        if (! $this->getParent()->isWritable()) {
            throw new fEnvironmentException(
                'The directory, %s, can not be renamed because the directory containing it is not writable',
                $this->directory
            );
        }

        // If the dirname does not contain any folder traversal, rename the dir in the current parent directory
        if (preg_match('#^[^/\\\\]+$#D', $new_dirname)) {
            $new_dirname = $this->getParent()->getPath().$new_dirname;
        }

        $info = fFilesystem::getPathInfo($new_dirname);

        if (! file_exists($info['dirname'])) {
            throw new fProgrammerException(
                'The new directory name specified, %s, is inside of a directory that does not exist',
                $new_dirname
            );
        }

        if (file_exists($new_dirname)) {
            if (! is_writable($new_dirname)) {
                throw new fEnvironmentException(
                    'The new directory name specified, %s, already exists, but is not writable',
                    $new_dirname
                );
            }
            if (! $overwrite) {
                $new_dirname = fFilesystem::makeUniqueName($new_dirname);
            }
        } else {
            $parent_dir = new fDirectory($info['dirname']);
            if (! $parent_dir->isWritable()) {
                throw new fEnvironmentException(
                    'The new directory name specified, %s, is inside of a directory that is not writable',
                    $new_dirname
                );
            }
        }

        // Allow filesystem transactions
        if (fFlysystem::isInsideTransaction()) {
            fFlysystem::rename($this->directory, $new_dirname);
        }

        fFlysystem::updateFilenameMapForDirectory($this->directory, $new_dirname);
    }

    /**
     * Performs a [http://php.net/scandir scandir()] on a directory, removing the `.` and `..` entries.
     *
     * If the `$filter` looks like a valid PCRE pattern - matching delimeters
     * (a delimeter can be any non-alphanumeric, non-backslash, non-whitespace
     * character) followed by zero or more of the flags `i`, `m`, `s`, `x`,
     * `e`, `A`, `D`,  `S`, `U`, `X`, `J`, `u` - then
     * [http://php.net/preg_match `preg_match()`] will be used.
     *
     * Otherwise the `$filter` will do a case-sensitive match with `*` matching
     * zero or more characters and `?` matching a single character.
     *
     * On all OSes (even Windows), directories will be separated by `/`s when
     * comparing with the `$filter`.
     *
     * @param string $filter    A PCRE or glob pattern to filter files/directories by path - directories can be detected by checking for a trailing / (even on Windows)
     * @param mixed  $recursive
     *
     * @return array The fFile (or fImage) and fDirectory objects for the files/directories in this directory
     */
    public function scan($filter = null, $recursive = false)
    {
        $this->tossIfDeleted();

        $objects = $this->getFlysystem()->listContents($this->getPath(), $recursive);

        if ($filter && ! preg_match('#^([^a-zA-Z0-9\\\\\s]).*\1[imsxeADSUXJu]*$#D', $filter)) {
            $filter = '#^'.strtr(
                preg_quote($filter, '#'),
                [
                    '\\*' => '.*',
                    '\\?' => '.',
                ]
            ).'$#D';
        }

        $results = [];
        foreach ($objects as $object) {
            if ($filter && ! preg_match($filter, $object['path'])) {
                continue;
            }

            $results[] = fFlysystem::createObject($object);
        }

        return $results;
    }

    /**
     * Performs a **recursive** [http://php.net/scandir scandir()] on a directory, removing the `.` and `..` entries.
     *
     * @param string $filter A PCRE or glob pattern to filter files/directories by path - see ::scan() for details
     *
     * @return array The fFile (or fImage) and fDirectory objects for the files/directories (listed recursively) in this directory
     */
    public function scanRecursive($filter = null)
    {
        return $this->scan($filter, true);
    }

    /**
     * Throws an exception if the directory has been deleted.
     */
    protected function tossIfDeleted(): void
    {
        if ($this->deleted) {
            throw new fProgrammerException(
                "The action requested can not be performed because the directory has been deleted\n\nBacktrace for fDirectory::delete() call:\n%s",
                fCore::backtrace(0, $this->deleted)
            );
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
