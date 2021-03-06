<?php

namespace PieCrust\TemplateEngines;

use \Dwoo;
use \Dwoo_Template_File;
use \Dwoo_Template_String;
use PieCrust\IPieCrust;
use PieCrust\PieCrustDefaults;
use PieCrust\Util\PathHelper;


class DwooTemplateEngine implements ITemplateEngine
{
    protected static $currentApp;
    
    public static function formatUri($uri)
    {
        return self::$currentApp->formatUri($uri);
    }
    
    public static function getPostUrlFormat($blogKey)
    {
        if ($blogKey == null) $blogKey = PieCrustDefaults::DEFAULT_BLOG_KEY;
        return self::$currentApp->getConfig()->getValueUnchecked($blogKey.'/post_url');
    }
    
    public static function getTagUrlFormat($blogKey)
    {
        if ($blogKey == null) $blogKey = PieCrustDefaults::DEFAULT_BLOG_KEY;
        return self::$currentApp->getConfig()->getValueUnchecked($blogKey.'/tag_url');
    }
    
    public static function getCategoryUrlFormat($blogKey)
    {
        if ($blogKey == null) $blogKey = PieCrustDefaults::DEFAULT_BLOG_KEY;
        return self::$currentApp->getConfig()->getValueUnchecked($blogKey.'/category_url');
    }
    
    protected $pieCrust;
    protected $dwoo;
    
    public function initialize(IPieCrust $pieCrust)
    {
        $this->pieCrust = $pieCrust;
    }
    
    public function getExtension()
    {
        return 'dwoo';
    }
    
    public function renderString($content, $data)
    {
        $this->ensureLoaded();
        $tpl = new Dwoo_Template_String($content);
        $this->dwoo->output($tpl, $data);
    }
    
    public function renderFile($templateName, $data)
    {
        $this->ensureLoaded();
        $templatePath = PathHelper::getTemplatePath($this->pieCrust, $templateName);
        $tpl = new Dwoo_Template_File($templatePath);
        $this->dwoo->output($tpl, $data);
    }
    
    public function clearInternalCache()
    {
    }
    
    protected function ensureLoaded()
    {
        if ($this->dwoo === null)
        {
            self::$currentApp = $this->pieCrust;
            
            $dir = $this->pieCrust->getCacheDir();
            if (!$dir) $dir = rtrim(sys_get_temp_dir(), '/\\') . '/';
            $compileDir = $dir . 'templates_c';
            if (!is_dir($compileDir)) mkdir($compileDir, 0777, true);
            $cacheDir = $dir . 'templates';
            if (!is_dir($cacheDir)) mkdir($cacheDir, 0777, true);
            
            require_once 'Dwoo/dwooAutoload.php';
            $this->dwoo = new Dwoo($compileDir, $cacheDir);
            $this->dwoo->getLoader()->addDirectory(PieCrustDefaults::APP_DIR . '/Plugins/Dwoo/');
        }
    }
}
