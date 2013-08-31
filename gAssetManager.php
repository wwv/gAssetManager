<?php

class gAssetManager extends CAssetManager
{
    public $minifyJs = true;
    public static $threaded;
    public static $pids = array();
    public $transformFunc = 'transformFile';
    public $newFileMode = 666; //BUG FIX
    public $newDirMode = 777; //BUG FIX

    /**
     * @var array published assets
     */
    private $_published = array();

    static function setThreadMode($threaded) {
        static::$threaded = $threaded;
    }

    function __construct() {
        if (static::$threaded) $this->transformFunc = 'transformFileThreaded';
        else $this->transformFunc = 'transformFile';
    }

    static function isPublishThreadFinished($src) {
        if (!isset(static::$pids[$src])) return true;
        exec('ps -p '.static::$pids[$src], $op);
        if (isset($op[1])) return false;
        else {
            unset(static::$pids[$src]);
            return true;
        }
    }

    static function isAllThreadsFinished() {
        foreach (static::$pids as $src=>$pid) {
            if (!static::isPublishThreadFinished($src)) return false;
        }
        return true;
    }

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
                        gFileHelper::copyDirectory(
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

    /*
     * Transform file to new file (or just copy)
     *
     * @param string $src    path to source
     * @param string $dst    path to destination
     * @param string $command    command to execute or 'copy'
     */

    protected function transformFile($src, $dst, $command, $makeGzip = true) {
        if ($command == 'copy') {
            $ok = copy($src, $dst);
            if (!$ok) {
                throw new Exception("Can not copy $src to $dst");
            }
        } else {
            exec($command, $output, $code);
            if($code) {
                throw new Exception("Can not execute: ".$command."\r\n".join(',', $output));
            }
        }
        @chmod($dst, $this->newFileMode);
        if ($makeGzip) self::makeGzipVersion($dst);
        return basename($dst);
    }

    /*
 * Transform file to new file (or just copy)
 *
 * @param string $src    path to source
 * @param string $dst    path to destination
 * @param string $command    command to execute or 'copy'
 */

    protected function transformFileThreaded($src, $dst, $command, $makeGzip = true) {
        if ($command == 'copy') {
            $command = "cp -f $src $dst";
        }
        if ($makeGzip) $command .= "&& gzip -fcq $dst > $dst.gz";
        $command .= "&& chmod -f {$this->newFileMode} $dst";

        //$time = microtime(true);
        self::$pids[$src] = exec("nohup sh -c '$command' > /dev/null 2>&1 & echo $!", $output, $code);
        //echo "running at ".(microtime(true)-$time)." seconds\r\n\r\n";


        if($code) {
            throw new Exception("Can not execute: ".$command."\r\n".join(',', $output));
        }
        return basename($dst);
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
    public function putFileToAsset($src, $dstFile)
    {
        if(preg_match('/\/bootstrap\//i', $src) ||
            preg_match('/\/imperavi-redactor-widget\//i', $src) ||
            preg_match('/\/yii-debug-toolbar\//i', $src) ||
            preg_match('/category-([a-z]{2}).js/i', $src)
        ) {
            // just copy into assets directory
            return $this->{$this->transformFunc}($src, $dstFile, 'copy');
        } else {
            $ext = pathinfo($src, PATHINFO_EXTENSION);
            switch ($ext) {
                case 'js':
                    if (!$this->minifyJs)
                        return $this->{$this->transformFunc}($src, $dstFile, 'copy');
                    else {
                        $compilerPath = dirname(__FILE__) . '/compiler.jar';
                        $command = "java -jar {$compilerPath} ";
                        $command .= "--js={$src} ";
                        $command .= "--js_output_file={$dstFile}";
                        return $this->{$this->transformFunc}($src, $dstFile, $command);
                    }
                case 'css':
                    // просто копируем в папку assets
                    return $this->{$this->transformFunc}($src, $dstFile, 'copy');
                case 'less':
                    // compile less into css
                    $dstFileCss = str_replace('.less', '.css', $dstFile);

                    $command = "lessc $src -x > $dstFileCss";
                    return $this->{$this->transformFunc}($src, $dstFileCss, $command);
                default:
                    // just copy into assets directory

                    $this->{$this->transformFunc}($src, $dstFile, 'copy', false);
                    break;
            }
        }
        return '';
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
}

class gFileHelper extends CFileHelper {
    private static $class = null;
    protected static function mkdir($dst, $options, $recursive) {
        if (!self::$class) self::$class = new ReflectionClass('CFileHelper');
        $mkdir = self::$class->getMethod('mkdir');
        $mkdir->setAccessible(true);
        $mkdir->invoke(null, $dst, $options, $recursive);
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
 * Copies a directory.
 */
    public static function copyDirectoryRecursive($src, $dst, $base, $fileTypes, $exclude, $level, $options)
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
                    $gA = new gAssetManager();
                    $fileName = $gA->putFileToAsset($path, $dst . DIRECTORY_SEPARATOR . $file, YII_DEBUG);
/*
                    if(isset($options['newFileMode']))
                    {
                        chmod($dst . DIRECTORY_SEPARATOR . $fileName, $options['newFileMode']);
                    }*/
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
}