<?php

namespace PieCrust\Formatters;

use PieCrust\IPieCrust;


class MarkdownFormatter implements IFormatter
{
    protected $markdownLibDir;
    
    public function initialize(IPieCrust $pieCrust)
    {
        $config = $pieCrust->getConfig();
        $this->markdownLibDir = 'Markdown';
        if ($pieCrust->getConfig()->getValue('markdown/use_markdown_extra') === true)
        {
            $this->markdownLibDir = 'MarkdownExtra';
        }
    }
    
    public function getPriority()
    {
        return IFormatter::PRIORITY_DEFAULT;
    }
    
    public function supportsFormat($format, $isUnformatted)
    {
        return $isUnformatted && preg_match('/markdown|mdown|mkdn?|md/i', $format);
    }
    
    public function format($text)
    {
        require_once ($this->markdownLibDir . '/markdown.php');
        return Markdown($text);
    }
}
