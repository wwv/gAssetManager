<?php

class gAssetManager extends CAssetManager
{
    public $minifyJs = true;

    /**
     * @var array published assets
     */
    private $_published = array();

    /**
     * Publishes a file or a directory.
     */
    public function publish($path, $hashByName = false, $level = -1, $forceCopy = null)
    {
        if($forceCopy === null)
        {
            $forceCopy = $this->forceCopy;
        }
        if($forceCopy && $this->linkAssets)
        {
            throw new CException(Yii::t('yii', 'The "forceCopy" and "linkAssets" cannot be both true.'));
        }
        if(isset($this->_published[$path]))
        {
            return $this->_published[$path];
        }
        elseif(($src = realpath($path)) !== false)
        {
            $dir = $this->generatePath($src, $hashByName);
            $dstDir = $this->getBasePath() . DIRECTORY_SEPARATOR . $dir;
            if(is_file($src))
            {
                $fileName = basename($src);
                $dstFile = $dstDir . DIRECTORY_SEPARATOR . $fileName;

                if(!is_dir($dstDir))
                {
                    mkdir($dstDir, $this->newDirMode, true);
                    chmod($dstDir, $this->newDirMode);
                }

                if ($this->linkAssets && !is_file($dstFile)) {
                    symlink($src, $dstFile);
                } elseif (@filemtime(str_replace('.less', '.css', $dstFile)) < @filemtime($src)) {
                    // put file into assets directory
                    $fileName = $this->putFileToAsset($src, $dstFile);
                } else {
                    $fileName = str_replace('.less', '.css', $fileName);
                }

                return $this->_published[$path] = $this->getBaseUrl() . "/$dir/$fileName";
            }
            elseif(is_dir($src))
            {
                if($this->linkAssets && !is_dir($dstDir))
                {
                    symlink($src, $dstDir);
                }
                elseif(!is_dir($dstDir) || $forceCopy)
                {
                    if (@filemtime($dstDir) < @filemtime($src)) {
                        self::copyDirectory(
                            $src, $dstDir, array(
                                                'exclude'     => $this->excludeFiles,
                                                'level'       => $level,
                                                'newDirMode'  => $this->newDirMode,
                                                'newFileMode' => $this->newFileMode,
                                           )
                        );
                    }
                }

                return $this->_published[$path] = $this->getBaseUrl() . '/' . $dir;
            }
        }
        throw new CException(Yii::t('yii',
                                    'The asset "{asset}" to be published does not exist.',
                                    array('{asset}' => $path)));
    }

    /**
     * Put file into assets directory
     *
     * @param      $src path to source file
     * @param      $dstFile path to file in assets directory
     * @param bool $jsOnlyGzip flag to make just gzip version of js, without minification
     *
     * @throws Exception
     * @return string path to result file
     */
    private function putFileToAsset($src, $dstFile, $jsOnlyGzip = false)
    {
        if(preg_match('/\/bootstrap\//i', $src) ||
            preg_match('/\/imperavi-redactor-widget\//i', $src) ||
            preg_match('/\/yii-debug-toolbar\//i', $src) ||
            preg_match('/category-([a-z]{2}).js/i', $src)
        )
        {;
            // just copy into assets directory
            copy($src, $dstFile);
            @chmod($dstFile, $this->newFileMode);
            self::makeGzipVersion($dstFile); // make gzip-version

            $fileName = basename($dstFile);
        }
        else
        {
            if(preg_match('/^.*\.js$/i', $src))
            {
                if (!$this->minifyJs)
                    copy($src, $dstFile);
                else
                {
                    $compilerPath = dirname(__FILE__) . '/compiler.jar';
                    $command = "java -jar {$compilerPath} ";
                    $command .= "--js={$src} ";
                    $command .= "--js_output_file={$dstFile}";
                    exec($command, $output, $code);
                    if($code)
                        throw new Exception("Can not compile JavaScript file $dstFile. Check permission rights.");
                }
                @chmod($dstFile, $this->newFileMode);
                self::makeGzipVersion($dstFile);
                $fileName = basename($dstFile);
            }
            elseif(preg_match('/^.*\.css$/i', $src)) // css
            {
                // просто копируем в папку assets
                copy($src, $dstFile);
                @chmod($dstFile, $this->newFileMode);

                self::makeGzipVersion($dstFile); // make gzip-version

                $fileName = basename($dstFile);
            }
            elseif(preg_match('/^.*\.less$/i', $src)) // less
            {
                // compile less into css
                $dstFileCss = str_replace('.less', '.css', $dstFile);
                exec("lessc $src -x > $dstFileCss", $output, $code);
                if($code)
                {
                    throw new Exception(__METHOD__ . " can not exec lessc $src -x > $dstFileCss:" . join(',', $output));
                }
                @chmod($dstFileCss, $this->newFileMode);

                self::makeGzipVersion($dstFileCss); // make gzip-version

                $fileName = basename($dstFileCss);
            }
            else
            {
                // just copy into assets directory
                copy($src, $dstFile);
                @chmod($dstFile, $this->newFileMode);

                $fileName = basename($dstFile);
            }
        }
        return $fileName;
    }

    /**
     * Make gzip version of file
     *
     * @param $pathToFile path to file
     */
    private function makeGzipVersion($pathToFile)
    {
        $dstFileGz = $pathToFile . '.gz';
        $fp = gzopen($dstFileGz, 'w9');
        gzwrite($fp, file_get_contents($pathToFile));
        gzclose($fp);
        @chmod($dstFileGz, $this->newFileMode);
    }

    /**
     * Copies a directory recursively as another.
     * If the destination directory does not exist, it will be created recursively.
     */
    public static function copyDirectory($src, $dst, $options = array())
    {
        $fileTypes = array();
        $exclude = array();
        $level = -1;
        extract($options);
        if(!is_dir($dst))
        {
            self::mkdir($dst, $options, true);
        }

        self::copyDirectoryRecursive($src, $dst, '', $fileTypes, $exclude, $level, $options);
    }

    /**
     * Shared environment safe version of mkdir. Supports recursive creation.
     * For avoidance of umask side-effects chmod is used.
     */
    private static function mkdir($dst, array $options, $recursive)
    {
        $prevDir = dirname($dst);
        if($recursive && !is_dir($dst) && !is_dir($prevDir))
        {
            self::mkdir(dirname($dst), $options, true);
        }

        $mode = isset($options['newDirMode']) ? $options['newDirMode'] : 0777;
        $res = mkdir($dst, $mode);
        chmod($dst, $mode);
        return $res;
    }

    /**
     * Copies a directory.
     */
    private static function copyDirectoryRecursive($src, $dst, $base, $fileTypes, $exclude, $level, $options)
    {
        if(!is_dir($dst))
        {
            self::mkdir($dst, $options, false);
        }

        $folder = opendir($src);
        while(($file = readdir($folder)) !== false)
        {
            if($file === '.' || $file === '..')
            {
                continue;
            }
            $path = $src . DIRECTORY_SEPARATOR . $file;
            $isFile = is_file($path);
            if(self::validatePath($base, $file, $isFile, $fileTypes, $exclude))
            {
                if($isFile)
                {
                    $gA = new gAsset();
                    $gA->putFileToAsset($path, $dst . DIRECTORY_SEPARATOR . $file, YII_DEBUG);

                    if(isset($options['newFileMode']))
                    {
                        chmod($dst . DIRECTORY_SEPARATOR . $file, $options['newFileMode']);
                    }
                }
                elseif($level)
                {
                    self::copyDirectoryRecursive($path, $dst . DIRECTORY_SEPARATOR . $file, $base . '/' . $file,
                                                 $fileTypes, $exclude, $level - 1, $options);
                }
            }
        }
        closedir($folder);
    }

    /**
     * Validates a file or directory.
     */
    protected static function validatePath($base, $file, $isFile, $fileTypes, $exclude)
    {
        foreach($exclude as $e)
        {
            if($file === $e || strpos($base . '/' . $file, $e) === 0)
            {
                return false;
            }
        }
        if(!$isFile || empty($fileTypes))
        {
            return true;
        }
        if(($type = pathinfo($file, PATHINFO_EXTENSION)) !== '')
        {
            return in_array($type, $fileTypes);
        }
        else
        {
            return false;
        }
    }
}
