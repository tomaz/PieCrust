<?php

namespace PieCrust\Page;

use \Exception;
use PieCrust\IPage;
use PieCrust\PieCrustDefaults;
use PieCrust\PieCrustException;
use PieCrust\Data\DataBuilder;
use PieCrust\Util\Configuration;


/**
 * This class is responsible for rendering the final page.
 */
class PageRenderer
{
    protected $page;
    /**
     * Gets the page this renderer is bound to.
     */
    public function getPage()
    {
        return $this->page;
    }
    
    /**
     * Creates a new instance of PageRenderer.
     */
    public function __construct(IPage $page)
    {
        $this->page = $page;
    }
    
    /**
     * Renders the given page and sends the result to the standard output.
     */
    public function render($data = null)
    {
        $pieCrust = $this->page->getApp();
        $pageConfig = $this->page->getConfig();
        
        // Get the template name.
        $templateName = $this->page->getConfig()->getValue('layout');
        if ($templateName == null or $templateName == '' or $templateName == 'none')
        {
            $templateName = false;
        }
        else
        {
            if (!preg_match('/\.[a-zA-Z0-9]+$/', $templateName))
            {
                $templateName .= '.html';
            }
        }
        
        if ($templateName !== false)
        {
            // Get the template engine and the page data.
            $extension = pathinfo($templateName, PATHINFO_EXTENSION);
            $templateEngine = $pieCrust->getTemplateEngine($extension);
            
            // We need to reset the pagination data so that any filters or modifications
            // applied to it by the page are not also applied to the template.
            $data = $this->getRenderData();
            $data['pagination']->resetPaginationData();
            
            // Render the page.
            $templateEngine->renderFile($templateName, $data);
        }
        else
        {
            // No template... just output the 'content' segment.
            echo $this->page->getContentSegment();
        }
        
        if ($pieCrust->isDebuggingEnabled())
        {
            // Add a footer with version, caching and timing information.
            $this->renderStatsFooter($this->page);
        }
    }
    
    public function get($data = null)
    {
        ob_start();
        try
        {
            $this->render($data);
            return ob_get_clean();
        }
        catch (Exception $e)
        {
            ob_end_clean();
            throw $e;
        }
    }
    
    public function renderStatsFooter()
    {
        $runInfo = $this->page->getApp()->getLastRunInfo();
        
        echo "<!-- PieCrust " . PieCrustDefaults::VERSION . " - ";
        echo ($this->page->wasCached() ? "baked this morning" : "baked just now");
        if ($runInfo['cache_validity'] != null)
        {
            $wasCacheCleaned = $runInfo['cache_validity']['was_cleaned'];
            echo ", from a " . ($wasCacheCleaned ? "brand new" : "valid") . " cache";
        }
        else
        {
            echo ", with no cache";
        }
        $timeSpan = microtime(true) - $runInfo['start_time'];
        echo ", in " . $timeSpan * 1000 . " milliseconds. -->";
    }
    
    protected function getRenderData()
    {
        $pieCrust = $this->page->getApp();
        $pageData = $this->page->getPageData();
        $pageContentSegments = $this->page->getContentSegments();
        $siteData = DataBuilder::getSiteData($pieCrust);
        $appData = DataBuilder::getAppData($pieCrust, $siteData, $pageData, $pageContentSegments, $this->page->wasCached());
        $renderData = Configuration::mergeArrays(
            $this->page->getContentSegments(),
            $pageData,
            $siteData,
            $appData
        );
        return $renderData;
    }
    
    public static function getHeaders($contentType, $server = null)
    {
        $mimeType = null;
        switch ($contentType)
        {
            case 'html':
                $mimeType = 'text/html';
                break;
            case 'xml':
                $mimeType = 'text/xml';
                break;
            case 'txt':
            case 'text':
            default:
                $mimeType = 'text/plain';
                break;
            case 'css':
                $mimeType = 'text/css';
                break;
            case 'xhtml':
                $mimeType = 'application/xhtml+xml';
                break;
            case 'atom':
                if ($server == null or strpos($server['HTTP_ACCEPT'], 'application/atom+xml') !== false)
                {
                    $mimeType = 'application/atom+xml';
                }
                else
                {
                    $mimeType = 'text/xml';
                }
                break;
            case 'rss':
                if ($server == null or strpos($server['HTTP_ACCEPT'], 'application/rss+xml') !== false)
                {
                    $mimeType = 'application/rss+xml';
                }
                else
                {
                    $mimeType = 'text/xml';
                }
                break;
            case 'json':
                $mimeType = 'application/json';
                break;
        }
        
        if ($mimeType != null)
        {
            return array('Content-type' => $mimeType. '; charset=utf-8');
        }
        return null;
    }
}

