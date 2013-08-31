<?php
define('TEST_THREADED', true);

class gAssetManagerTest extends CTestCase {
    /*
     * @var gAssetManager $am
     * @var string $src source directory
     * @var string $dst destination directory
     * @var array $src_files list of files in source directory
     */
    private static $am;
    private static $src;
    private static $dst;
    private static $amPath;
    private static $src_files;
    static function setUpBeforeClass() {
        parent::setUpBeforeClass();
        //$am = Yii::app()->getComponent('assetManager');
        shell_exec('rm -rf '.__DIR__.'/../../assets/*');
        gAssetManager::setThreadMode(TEST_THREADED);
        self::$am = new gAssetManager();
        self::$src = __DIR__.'/testassets';

        self::$amPath = self::$am->publish(self::$src);
        self::$dst = dirname($_SERVER['SCRIPT_FILENAME']).'/'.self::$amPath;

        self::$src_files = self::getAllFiles(self::$src);
        if (TEST_THREADED) {
            while (!gAssetManager::isAllThreadsFinished()) {
                sleep(1);
            }
        }
    }

    function getAllFiles($from) {
        /*
         * @param string $from directory from which files received
         */
        $items = glob($from.'/*');
        for ($i = 0; $i < count($items); $i++) {
            if (is_dir($items[$i])) {
                $add = glob($items[$i] . '/*');
                $items = array_merge($items, $add);for ($i = 0; $i < count($items); $i++) {
                    if (is_dir($items[$i])) {
                        $add = glob($items[$i] . '/*');
                        $items = array_merge($items, $add);
                    }
                }
            }
        }
        return $items;
    }


    function testPublishResult() {
        $this->assertNotEmpty(self::$amPath);
        $this->assertFileExists(self::$dst);
    }

    function testCopiedFilesExistsInDestination() {
        foreach (self::$src_files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (($ext == 'less') || ($ext == 'js')) continue;
            $newPath = str_replace(array(self::$src), array(self::$dst), $file);
            $this->assertFileExists($newPath);
            if (filesize($file)) $this->assertTrue(filesize($newPath) > 0);
        }
    }

    function testLessFilesExistsInDestination() {
        foreach (self::$src_files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext != 'less') continue;
            $newPath = str_replace(array(self::$src, '.less'), array(self::$dst, '.css'), $file);
            $this->assertFileExists($newPath);
            if (filesize($file)) $this->assertTrue(filesize($newPath) > 0);
        }
    }

    function testJsMinifiedFilesExistsInDestination() {
        foreach (self::$src_files as $file) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext != 'js') continue;
            $newPath = str_replace(array(self::$src), array(self::$dst), $file);
            $this->assertFileExists($newPath);
            $this->assertFileNotEquals($file, $newPath);
            if (filesize($file)) $this->assertTrue(filesize($newPath) > 0);
        }
    }

    function testGzippedFilesExistsInDestination() {
        foreach (self::$src_files as $file) {
            $newPath = str_replace(self::$src, self::$dst, $file);
            $ext = pathinfo($newPath, PATHINFO_EXTENSION);
            if (in_array($ext, array('css', 'js'))){
                $this->assertFileExists($newPath.'.gz');
            }
        }
    }

    //caching logic check below, not dependent from threads
    function testDuplicatesInOtherDirsNotCreated() {
        gAssetManager::setThreadMode(false);
        $am = new gAssetManager();
        $singleSrc = __DIR__.'/testassets/form.css';
        $singleDst1 = $am->publish($singleSrc);
        $time = time();
        touch($singleSrc, $time);
        $singleDst2 = $am->publish($singleSrc);
        $this->assertEquals($singleDst1, $singleDst2);
    }

    function testNewFileRewritesOldDestination() {
        gAssetManager::setThreadMode(false);
        $am = new gAssetManager();
        $singleSrc = __DIR__.'/testassets/form.css';
        $time = time();
        touch($singleSrc, $time);
        $singleDst = $am->publish($singleSrc);
        $dstTime = filemtime(dirname($_SERVER['SCRIPT_FILENAME']).'/'.$singleDst);
        $this->assertEquals($time, $dstTime);
    }

    function testOlderFileNotRewritesDestination() {
        gAssetManager::setThreadMode(false);
        $am = new gAssetManager();
        $singleSrc = __DIR__.'/testassets/form.css';
        $am->publish($singleSrc);
        $time1 = time()-1000;
        touch($singleSrc, $time1);
        $singleDst = $am->publish($singleSrc);

        //$time1 = filemtime($singleSrc);
        $time2 = filemtime(dirname($_SERVER['SCRIPT_FILENAME']).'/'.$singleDst);
        $this->assertLessThan($time2, $time1);
    }

    function testOlderFilesInDirNotRewritesDestination() {
        gAssetManager::setThreadMode(false);
        $am = new gAssetManager();
        $time = time()-1000;
        foreach (self::$src_files as $file) {
            touch($file, $time);
        }
        $amPath = $am->publish(self::$src);
        $dst = dirname($_SERVER['SCRIPT_FILENAME']).'/'.$amPath;
        $dst_files = $this->getAllFiles($dst);
        foreach ($dst_files as $file) {
            $time2 = filemtime($file);
            $this->assertLessThan($time2, $time);
        }
    }

    //performance comparison test
    function testThreadedPerformance100x(){
        shell_exec('rm -rf '.__DIR__.'/../../assets/*');
        gAssetManager::setThreadMode(false);
        $am = new gAssetManager();
        $start_time = microtime(true);
        $am->publish(self::$src);
        $rawTime = microtime(true) - $start_time;
        file_put_contents('./performance.log', 'Raw publish: '.$rawTime.' sec'.PHP_EOL);

        shell_exec('rm -rf '.__DIR__.'/../../assets/*');
        gAssetManager::setThreadMode(true);
        $am = new gAssetManager();
        $start_time = microtime(true);
        $am->publish(self::$src);
        $threadedTime = microtime(true) - $start_time;
        file_put_contents('./performance.log', 'Threaded publish: '.$threadedTime.' sec', FILE_APPEND);

        $this->assertLessThan($rawTime, $threadedTime*100, "Not too fast: threaded $threadedTime sec; not threaded $rawTime sec");
    }

}
  