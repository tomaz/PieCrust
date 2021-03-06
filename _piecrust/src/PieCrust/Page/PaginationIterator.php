<?php

namespace PieCrust\Page;

use \Iterator;
use \Countable;
use \ArrayAccess;
use PieCrust\IPage;
use PieCrust\PieCrustException;
use PieCrust\Page\Filtering\PaginationFilter;
use PieCrust\Util\UriBuilder;
use PieCrust\Util\PageHelper;


/**
 * A class that can return several pages with filtering and all.
 *
 * The data-source must be an array with the same keys/values as what's returned
 * by FileSystem::getPostFiles().
 *
 * @formatObject
 * @explicitInclude
 */
class PaginationIterator implements Iterator, ArrayAccess, Countable
{
    protected $dataSource;
    protected $parentPage;
    
    protected $filter;
    protected $skip;
    protected $limit;
    
    protected $posts;
    protected $hasMorePosts;
    protected $totalPostCount;
    
    public function __construct(IPage $parentPage, array $dataSource)
    {
        $this->parentPage = $parentPage;
        $this->dataSource = $dataSource;
        
        $this->filter = null;
        $this->skip = 0;
        $this->limit = -1;
        
        $this->posts = null;
        $this->hasMorePosts = false;
        $this->totalPostCount = 0;
    }
    
    public function setFilter(PaginationFilter $filter)
    {
        $this->ensureNotLoaded('setFilter');
        $this->filter = $filter;
    }

    public function getTotalPostCount()
    {
        $this->ensureLoaded();
        return $this->totalPostCount;
    }
    
    public function hasMorePosts()
    {
        $this->ensureLoaded();
        return $this->hasMorePosts;
    }
    
    // {{{ Fluent-interface filtering members
    /**
     * @include
     * @noCall
     */
    public function skip($count)
    {
        $this->ensureNotLoaded('skip');
        $this->skip = $count;
        return $this;
    }
    
    /**
     * @include
     * @noCall
     */
    public function limit($count)
    {
        $this->ensureNotLoaded('limit');
        $this->limit = $count;
        return $this;
    }
    
    /**
     * @include
     * @noCall
     */
    public function filter($filterName)
    {
        $this->ensureNotLoaded('filter');
        if (!$this->parentPage->getConfig()->hasValue($filterName))
            throw new PieCrustException("Couldn't find filter '".$filterName."' in the page's configuration header.");
        
        $filterDefinition = $this->parentPage->getConfig()->getValue($filterName);
        $this->filter = new PaginationFilter();
        $this->filter->addClauses($filterDefinition);
        return $this;
    }

    /**
     * @include
     * @noCall
     */
    public function in_category($category)
    {
        $this->ensureNotLoaded('in_category');

        $this->filter = new PaginationFilter();
        $this->filter->addClauses(array('is_category' => $category));
        return $this;
    }

    /**
     * @include
     * @noCall
     */
    public function with_tag($tag)
    {
        $this->ensureNotLoaded('with_tag');

        $this->filter = new PaginationFilter();
        $this->filter->addClauses(array('has_tags' => $tag));
        return $this;
    }

    /**
     * @include
     * @noCall
     */
    public function with_tags($tag1, $tag2 /*, $tag3, ... */)
    {
        $this->ensureNotLoaded('with_tags');

        $tagClauses = array();
        $argCount = func_num_args();
        for ($i = 0; $i < $argCount; ++$i)
        {
            $tag = func_get_arg($i);
            $tagClauses['has_tags'] = $tag;
        }

        $this->filter = new PaginationFilter();
        $this->filter->addClauses(array('and' => $tagClauses));
        return $this;
    }
    
    /**
     * @include
     * @noCall
     */
    public function all()
    {
        $this->ensureNotLoaded('all');
        $this->filter = null;
        $this->skip = 0;
        $this->limit = -1;
        return $this;
    }
    // }}}
    
    // {{{ Countable members
    /**
     * @include
     * @noCall
     */
    public function count()
    {
        $this->ensureLoaded();
        return count($this->posts);
    }
    // }}}
    
    // {{{ Iterator members
    public function current()
    {
        $this->ensureLoaded();
        return current($this->posts);
    }
    
    public function key()
    {
        $this->ensureLoaded();
        return key($this->posts);
    }
    
    public function next()
    {
        $this->ensureLoaded();
        next($this->posts);
    }
    
    public function rewind()
    {
        $this->ensureLoaded();
        reset($this->posts);
    }
    
    public function valid()
    {
        $this->ensureLoaded();
        return (key($this->posts) !== null);
    }
    // }}}
    
    // {{{ ArrayAccess members
    public function offsetExists($offset)
    {
        if (!is_int($offset))
            return false;
        
        $this->ensureLoaded();
        return isset($this->posts[$offset]);
    }
    
    public function offsetGet($offset)
    {
        if (!is_int($offset))
           throw new OutOfRangeException();
            
        $this->ensureLoaded();
        return $this->posts[$offset];
    }
    
    public function offsetSet($offset, $value)
    {
        throw new PieCrustException("The pagination is read-only.");
    }
    
    public function offsetUnset($offset)
    {
        throw new PieCrustException("The pagination is read-only.");
    }
    // }}}
    
    protected function ensureNotLoaded($func)
    {
        if ($this->posts != null)
            throw new PieCrustException("Can't call '".$func."' after the pagination posts have been loaded.");
    }
    
    protected function ensureLoaded()
    {
        if ($this->posts != null)
            return;
        
        $upperLimit = count($this->dataSource);
        if ($this->limit > 0)
            $upperLimit = min($this->skip + $this->limit, count($this->dataSource));
        
        $this->hasMorePosts = false;
        $pieCrust = $this->parentPage->getApp();
        $blogKey = $this->parentPage->getConfig()->getValue('blog');
        $postsUrlFormat = $pieCrust->getConfig()->getValueUnchecked($blogKey.'/post_url');
        
        if ($this->filter != null and $this->filter->hasClauses())
        {
            // We have some filtering clause: that's tricky because we
            // need to filter posts using those clauses from the start to
            // know what offset to start from, and we need to enumerate it
            // all until the end to know the total number of posts.
            // This is not very efficient and at this point the user might 
            // as well bake his website but this is still fast enough for 
            // previewing in the 'chef server'.
            $this->totalPostCount = 0;
            $filteredDataSource = array();
            foreach ($this->dataSource as $postInfo)
            {
                // TODO: this will load *all* pages matching the given filter!
                // TODO: we should probably, inside the loop, unload pages we
                // TODO: know won't get into the relevant slice.
                if (!isset($postInfo['page']))
                {
                    $postInfo['page'] = PageRepository::getOrCreatePage(
                        $pieCrust,
                        UriBuilder::buildPostUri($postsUrlFormat, $postInfo), 
                        $postInfo['path'],
                        IPage::TYPE_POST,
                        $blogKey);
                }
                
                if ($this->filter->postMatches($postInfo['page']))
                {
                    $filteredDataSource[] = $postInfo;
                }
            }
            
            // Now get the slice of the filtered post infos that is relevant
            // for the current page number, the total number of posts, and see
            // if there's more past our current page.
            $limitedFilteredDataSource = array_slice($filteredDataSource, $this->skip, $upperLimit - $this->skip);
            $this->posts = $this->getPostsData($limitedFilteredDataSource);
            $this->totalPostCount = count($filteredDataSource);
            if ($this->limit > 0)
            {
                $this->hasMorePosts = (count($filteredDataSource) > ($this->skip + $this->limit));
            }
        }
        else
        {
            // This is a normal page, or a situation where we don't do any filtering.
            // That's easy, we just return the portion of the posts-infos array that
            // is relevant to the current page. We just need to add the built page objects.
            $relevantSlice = array_slice($this->dataSource, $this->skip, $upperLimit - $this->skip);
            
            $filteredDataSource = array();
            foreach ($relevantSlice as $postInfo)
            {
                if (!isset($postInfo['page']))
                {
                    $postInfo['page'] = PageRepository::getOrCreatePage(
                        $pieCrust,
                        UriBuilder::buildPostUri($postsUrlFormat, $postInfo), 
                        $postInfo['path'],
                        IPage::TYPE_POST,
                        $blogKey);
                }
                
                $filteredDataSource[] = $postInfo;
            }
            
            // Get the posts data, the total number of posts, and see if this slice 
            // reaches the end of the data source.
            $this->posts = $this->getPostsData($filteredDataSource);
            $this->totalPostCount = count($this->dataSource);
            if ($this->limit > 0)
            {
                $this->hasMorePosts = (count($this->dataSource) > ($this->skip + $this->limit));
            }
        }
    }
    
    protected function getPostsData($postInfos)
    {
        $postsData = array();
        $pieCrust = $this->parentPage->getApp();
        $blogKey = $this->parentPage->getConfig()->getValue('blog');
        $postsDateFormat = PageHelper::getConfigValue($this->parentPage, 'date_format', $blogKey);
        foreach ($postInfos as $postInfo)
        {
            // Create the post with all the stuff we already know.
            $post = $postInfo['page'];
            $post->setAssetUrlBaseRemap($this->parentPage->getAssetUrlBaseRemap());
            $post->setDate(PageHelper::getPostDate($postInfo));

            // Build the pagination data entry for this post.
            $postData = $post->getConfig();
            $postData['url'] = $pieCrust->formatUri($post->getUri());
            $postData['slug'] = $post->getUri();
            
            $timestamp = $post->getDate();
            if ($post->getConfig()->getValue('time'))
            {
                $timestamp = strtotime($post->getConfig()->getValue('time'), $timestamp);
            }
            $postData['timestamp'] = $timestamp;
            $postData['date'] = date($postsDateFormat, $timestamp);
            
            $postHasMore = false;
            $postContents = $post->getContentSegment('content');
            if ($post->hasContentSegment('content.abstract'))
            {
                $postContents = $post->getContentSegment('content.abstract');
                $postHasMore = true;
            }
            $postData['content'] = $postContents;
            $postData['has_more'] = $postHasMore;
            
            $postsData[] = $postData;
        }
        return $postsData;
    }
}
