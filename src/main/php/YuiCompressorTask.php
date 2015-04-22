<?php
/**
 * Created by IntelliJ IDEA.
 * User: brandencash
 * Date: 4/21/15
 * Time: 7:59 PM
 */

class YuiCompressorTask extends Task
{
    /** @var string The path to java */
    private $java = 'java';

    /** @var string The path to the yui compressor bin */
    private $jar = 'vendor/bin/yuicompressor.jar';

    /** @var PhingFile The target directory to place minified files ... if not set, we will just put it in the same directory with the suffix .min.$ext */
    private $targetDir = null;

    /** @var string|null File to store cached data in */
    private $cacheFile = 'yuic.cache';

    /** @var string The template used for creating the new file name */
    private $nameTemplate = '{{dirname}}/{{filename}}.min.{{extension}}';

    /** @var FileSet[] Any fileSets of files that should be appended. */
    private $fileSets = array();

    /** @var FileList[] Any fileLists of files that should be appended. */
    private $fileLists = array();

    /** @var FilterChain[] Any filters to be applied before append happens. */
    private $filterChains = array();

    private $cache = array();

    /**
     * Supports embedded <filelist> element.
     * @return FileList
     */
    function createFileList()
    {
        $num = array_push($this->fileLists, new FileList());
        return $this->fileLists[$num-1];
    }

    /**
     * Nested creator, adds a set of files (nested <fileset> attribute).
     * This is for when you don't care what order files get appended.
     * @return FileSet
     */
    function createFileSet()
    {
        $num = array_push($this->fileSets, new FileSet());
        return $this->fileSets[$num-1];
    }

    /**
     * Creates a filterchain
     *
     * @return FilterChain The created filterchain object
     */
    function createFilterChain()
    {
        $num = array_push($this->filterChains, new FilterChain($this->project));
        return $this->filterChains[$num-1];
    }

    /**
     *  Called by the project to let the task do it's work. This method may be
     *  called more than once, if the task is invoked more than once. For
     *  example, if target1 and target2 both depend on target3, then running
     *  <em>phing target1 target2</em> will run all tasks in target3 twice.
     *
     *  Should throw a BuildException if someting goes wrong with the build
     *
     *  This is here. Must be overloaded by real tasks.
     */
    public function main()
    {
        if (!file_exists($this->jar)) {
            throw new BuildException("Must specify path to yui compressor using `jar` parameter");
        }
        $this->loadCache();
        // append the files in the fileLists
        foreach($this->fileLists as $fl) {
            try {
                $files = $fl->getFiles($this->project);
                $context = $fl->getDir($this->project);
                foreach ($files as $file) {
                    //$file = new PhingFile($fl->getDir($this->project), $file);
                    //$file = $fl->dir . DIRECTORY_SEPARATOR . $file;
                    $in = FileUtils::getChainedReader(new FileReader($file), $this->filterChains, $this->project);
                    while (-1 !== ($buffer = $in->read())) {
                        $this->minify($context, $file, $buffer);
                        $this->saveCache();
                    }
                }
            } catch (BuildException $be) {
                $this->log($be->getMessage(), Project::MSG_WARN);
            }
        }

        // append any files in fileSets
        foreach($this->fileSets as $fs) {
            try {
                $files = $fs->getDirectoryScanner($this->project)->getIncludedFiles();
                $context = $fs->getDir($this->project);
                foreach ($files as $file) {
                    //$file = new PhingFile($context, $file);
                    $in = FileUtils::getChainedReader(new FileReader($file), $this->filterChains, $this->project);
                    while (-1 !== ($buffer = $in->read())) {
                        $this->minify($context, $file, $buffer);
                        $this->saveCache();
                    }
                }
            } catch (BuildException $be) {
                $this->log($be->getMessage(), Project::MSG_WARN);
            }
        }
        $this->saveCache();
    }

    protected function loadCache()
    {
        $file = $this->getCacheFile();
        if (file_exists($file)) {
            $this->cache = json_decode(file_get_contents($file), true);
        } else {
            $this->cache = array();
        }
    }

    protected function saveCache()
    {
        $file = $this->getCacheFile();
        $dir = dirname($file);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($file, json_encode($this->cache));
    }

    protected function cachedMD5(PhingFile $file, $data, PhingFile $target)
    {
        $cache = @$this->cache[$file->getCanonicalPath()];
        $modified = filemtime($file->getAbsolutePath());
        if (!$target->exists() || !$cache || $modified != $cache['modified']) {
            return md5($data);
        }
        $md5 = md5($data);
        if ($md5 != $cache['md5']) {
            return $md5;
        }
        return false;
    }

    protected function minify($basedir, $file, $data)
    {
        $source = new PhingFile($basedir, $file);
        $targetDir = new PhingFile($this->targetDir ?: $basedir);
        $name = $this->nameTemplate;
        $nameParts = pathinfo($file);
        foreach ($nameParts as $key=>$value) {
            $name = str_replace('{{'.$key.'}}', $value, $name);
        }
        $target = new PhingFile($targetDir, $name);
        @mkdir(dirname($target->getAbsolutePath()), 0755, true);

        $md5 = $this->cachedMD5($source, $data, $target);
        if ($md5 === false) {
            $this->log("No change in {$source->getPath()}", Project::MSG_VERBOSE);
            return true;
        }
        $this->log("Minifying {$source->getPath()} to {$target->getPath()}", Project::MSG_INFO);
        $cmd = escapeshellcmd($this->java)
            . ' -jar ' . escapeshellarg($this->jar)
            . ' -o ' . escapeshellarg($target->getAbsolutePath())
            . ' ' . escapeshellarg($source->getAbsolutePath());
        $this->log('Executing: ' . $cmd, Project::MSG_DEBUG);
        exec($cmd, $output, $return);
        if ($return !== 0) {
            $this->log($output, Project::MSG_ERR);
            throw new BuildException("Failed to minify {$source->getPath()}");
        }
        $this->cache[$source->getCanonicalPath()] = array(
            'modified' => filemtime($source->getAbsolutePath()),
            'md5' => $md5,
        );
        return true;
    }

    /**
     * @param string $jar
     */
    public function setJar($jar)
    {
        $this->jar = $jar;
    }

    /**
     * @param PhingFile $targetDir
     */
    public function setTargetDir(PhingFile $targetDir)
    {
        $this->targetDir = $targetDir;
    }

    /**
     * @return null|string
     */
    public function getCacheFile()
    {
        return $this->cacheFile;
    }

    /**
     * @param null|string $cacheFile
     */
    public function setCacheFile($cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    /**
     * @param string $java
     */
    public function setJava($java)
    {
        $this->java = $java;
    }

    /**
     * @param string $nameTemplate
     */
    public function setNameTemplate($nameTemplate)
    {
        $this->nameTemplate = $nameTemplate;
    }
}
 