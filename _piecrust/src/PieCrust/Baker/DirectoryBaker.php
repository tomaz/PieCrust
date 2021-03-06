<?php

namespace PieCrust\Baker;

use \Exception;
use \DirectoryIterator;
use PieCrust\IPieCrust;
use PieCrust\PieCrustDefaults;
use PieCrust\PieCrustException;
use PieCrust\Util\PluginLoader;


/**
 * A class responsible for baking non-PieCrust content.
 */
class DirectoryBaker
{
    protected $pieCrust;
    protected $rootDirLength;
    protected $bakeDir;
    protected $parameters;

    protected $bakedFiles;
    /**
     * Gets the files baked last time.
     */
    public function getBakedFiles()
    {
        return $this->bakedFiles;
    }
    
    protected $processorsLoader;
    /**
    * Gets the PluginLoader for the file processors.
    */
    public function getProcessorsLoader()
    {
        if ($this->processorsLoader === null)
        {
            $processorsToFilter = $this->parameters['processors'];
            $this->processorsLoader = new PluginLoader(
                'PieCrust\\Baker\\Processors\\IProcessor',
                PieCrustDefaults::APP_DIR . '/Baker/Processors',
                function ($p1, $p2) { return $p1->getPriority() < $p2->getPriority(); },
                $processorsToFilter == '*' ?
                    null :
                    function ($p) use ($processorsToFilter) { return in_array($p->getName(), $processorsToFilter); },
                'SimpleFileProcessor.php'
            );
            foreach ($this->processorsLoader->getPlugins() as $proc)
            {
                $proc->initialize($this->pieCrust);
            }
        }
        return $this->processorsLoader;
    }
    
    /**
     * Creates a new instance of DirectoryBaker.
     */
    public function __construct(IPieCrust $pieCrust, $bakeDir, array $parameters = array())
    {
        $this->pieCrust = $pieCrust;
        $this->bakeDir = rtrim(str_replace('\\', '/', $bakeDir), '/') . '/';
        $this->parameters = array_merge(
                                        array(
                                              'smart' => true,
                                              'skip_patterns' => array(),
                                              'force_patterns' => array(),
                                              'processors' => array('copy')
                                              ),
                                        $parameters
                                        );
        
        // Compute the number of characters we need to remove from file paths
        // to get their relative paths.
        $rootDir = $this->pieCrust->getRootDir();
        $rootDir = rtrim($rootDir, '/\\') . '/';
        $this->rootDirLength = strlen($rootDir);

        $this->bakedFiles = null;
        
        if (!is_dir($this->bakeDir) or !is_writable($this->bakeDir))
        {
            throw new PieCrustException('The bake directory is not writable, or does not exist: ' . $this->bakeDir);
        }
    }
    
    /**
     * Bakes the given directory and all its files and sub-directories.
     */
    public function bake()
    {
        $this->bakedFiles = array();
        $this->bakeDirectory($this->pieCrust->getRootDir(), 0);
    }
    
    protected function bakeDirectory($currentDir, $level)
    {
        $it = new DirectoryIterator($currentDir);
        foreach ($it as $i)
        {
            if ($i->isDot())
            {
                continue;
            }

            // Figure out the root-relative path.
            $relative = substr($i->getPathname(), $this->rootDirLength);

            // See if we need to skip this file/directory.
            $shouldSkip = false;
            foreach ($this->parameters['skip_patterns'] as $p)
            {
                if (preg_match($p, $relative))
                {
                    $shouldSkip = true;
                    break;
                }
            }
            if ($shouldSkip)
                continue;
            
            if ($i->isDir())
            {
                // Current path is a directory... recurse into it, unless it's
                // actually the directory we're baking *into* (which would cause
                // an infinite loop and lots of files being created!).
                $normalizedPathname = rtrim(str_replace('\\', '/', $i->getPathname()), '/') . '/';
                if ($normalizedPathname == $this->bakeDir)
                {
                    continue;
                }

                $destination = $this->bakeDir . $relative;
                if (!is_dir($destination))
                {
                    if (@mkdir($destination, 0777, true) === false)
                        throw new PieCrustException("Can't create directory: " . $destination);
                }
                $this->bakeDirectory($i->getPathname(), $level + 1);
            }
            else if ($i->isFile())
            {
                // Current path is a file... first, find a processor for it.
                $fileProcessor = null;
                foreach ($this->getProcessorsLoader()->getPlugins() as $proc)
                {
                    if ($proc->supportsExtension(pathinfo($i->getFilename(), PATHINFO_EXTENSION)))
                    {
                        $fileProcessor = $proc;
                        break;
                    }
                }
                if ($fileProcessor != null)
                {
                    $destinationDir = $this->bakeDir . dirname($relative) . DIRECTORY_SEPARATOR;

                    // Figure out if we need to actually process this file.
                    $isUpToDate = false;
                    
                    // Should the file be force-baked?
                    foreach ($this->parameters['force_patterns'] as $p)
                    {
                        if (preg_match($p, $i->getFilename()))
                        {
                            $shouldSkip = true;
                            break;
                        }
                    }

                    // Is the output file up to date with its input and dependencies?
                    if ($this->parameters['smart'])
                    {
                        $isUpToDate = true;

                        // Get the paths and last modification times for the input file and
                        // all its dependencies, if any.
                        $inputFilenames = array($i->getPathname() => $i->getMTime());
                        try
                        {
                            $dependencies = $fileProcessor->getDependencies($i->getPathname());
                            if ($dependencies)
                            {
                                foreach ($dependencies as $dep)
                                {
                                    $inputFilenames[$dep] = filemtime($dep);
                                }
                            }
                        }
                        catch(Exception $e)
                        {
                            echo "Warning: " . $e->getMessage() . " -- Will force-bake " . $relative . PHP_EOL;
                            $isUpToDate = false;
                        }

                        if ($isUpToDate)
                        {
                            // Get the paths and last modification times for the output files.
                            $outputFilenames = array();
                            $outputs = $fileProcessor->getOutputFilenames($i->getFilename());
                            if (!is_array($outputs))
                            {
                                $outputs = array($outputs);
                            }
                            foreach ($outputs as $out)
                            {
                                $fullOut = $destinationDir . $out;
                                $outputFilenames[$fullOut] = is_file($fullOut) ? filemtime($fullOut) : false;
                            }

                            // Compare those times to see if the output file is up to date.
                            foreach ($inputFilenames as $iFn => $iTime)
                            {
                                foreach ($outputFilenames as $oFn => $oTime)
                                {
                                    if (!$oTime || $iTime >= $oTime)
                                    {
                                        $isUpToDate = false;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                    if (!$isUpToDate)
                    {
                        try
                        {
                            $start = microtime(true);
                            $fileProcessor->process($i->getPathname(), $destinationDir);
                            $this->bakedFiles[] = $relative;
                            echo PieCrustBaker::formatTimed($start, $relative) . PHP_EOL;
                        }
                        catch (Exception $e)
                        {
                            throw new PieCrustException("Error processing '" . $relative . "': " . $e->getMessage(), 0, $e);
                        }
                    }
                }
                else
                {
                    echo "Warning: No processor for " . $relative . PHP_EOL;
                }
            }
        }
    }
}
