<?php

require_once 'lda.inc.php';

class LinkedDataApiCachedResponse
{
	var $eTag;
	var $generatedTime;
	var $lastModified;
	var $mimetype;
	var $body;
	var $cacheable;

	function serve()
	{
		header("Content-type: {$this->mimetype}");
		header("Last-Modified: {$this->lastModified}");
		header("ETag: {$this->eTag}");
		header("x-served-from-cache: true");
		echo $this->body;
	}
}

class LinkedDataApiCache
{

  var $connection = false;
  var $_options = array();

    function __construct($options=array()){
        $this->_options = $options;
        $this->connection = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
    }

    public function load($id, $doNotTestCacheValidity = FALSE, $doNotUnserialize = FALSE) {
        if(!$this->connection) $this->connection = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
        if(@$tmp = $this->connection->get($id)){
          if (is_array($tmp)) {
              logDebug("Returned valid response from cache for id: ".$id);
              return $tmp[0];
          }
        }
        return false;
    }

    function getLifetime($specificLifetime){
      if($specificLifetime){
          return PUELIA_CACHE_AGE;
      }
    }

    public function save($data, $id, $tags = array(), $specificLifetime = false, $priority=0){
      
      $lifetime = $this->getLifetime($specificLifetime);
        
        if(!$this->connection) $this->connection = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
        
        if (isset($this->_options['compression']) AND $this->_options['compression']) {
            $flag = MEMCACHE_COMPRESSED;
        } else {
            $flag = 0;
        }
        if ($this->test($id)) {
            // because set and replace seems to have different behaviour
            @$result = $this->connection->replace($id, array($data, time(), $lifetime), $flag, $lifetime);
        } else {
            @$result = $this->connection->set($id, array($data, time(), $lifetime), $flag, $lifetime);
        }
       return $result;
 
    }

    public function remove($id){
      
      if(!$this->connection) $this->connection = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
      
      return $this->connection->delete($id);    
    }

    public function test($id){
    
        if(@$tmp = $this->connection->get($id)){
          if (is_array($tmp)) {
              return $tmp[1];
          }
        }

        return false;
    
    }

	public static function hasCachedResponse(LinkedDataApiRequest $request)
	{
		if(!function_exists("memcache_connect")) return false;
	
		$acceptableTypes = self::getAcceptableTypes($request);
		$uri = $request->getOrderedUriWithoutApiKeys();
		
		foreach ($acceptableTypes as $mimetype)
		{
			$key = LinkedDataApiCache::cacheKey($uri, $mimetype);
			$mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
			$cachedObject = $mc->get($key);
			if ($cachedObject)
			{
				logDebug("Found a cached response for $mimetype under key $key");
				return $cachedObject;
			} 
			logDebug("No cached response for $mimetype under key $key");
		}
		logDebug('No suitable cached responses found');
		return false;
	}
	
	private static function getAcceptableTypes($request){
	    global $outputFormats;
	    
	    $extension = $request->getFormatExtension();
	    if ($extension!==false AND $extension!='ico'){
	        $acceptableTypes = $outputFormats[$extension]['mimetypes'];
	    }
	    else {
	        $formatParam = $request->getParam('_format');
	        if ($formatParam!=null){
	            $acceptableTypes = $outputFormats[$formatParam]['mimetypes'];
	        }
	        else{
	            $acceptableTypes = $request->getAcceptTypes();
	        }
	    }
	    return $acceptableTypes;
	}

	public static function cacheResponse(LinkedDataApiRequest $request, LinkedDataApiResponse $response)
	{
		if(!function_exists("memcache_connect")) return false;
		$cacheableResponse = new LinkedDataApiCachedResponse();
		$cacheableResponse->eTag = $response->eTag;
		$cacheableResponse->generatedTime = $response->generatedTime;
		$cacheableResponse->lastModified = $response->lastModified;
		$cacheableResponse->mimetype = $response->mimetype;
		$cacheableResponse->body = $response->body;

		$key = LinkedDataApiCache::cacheKey($request->getOrderedUriWithoutApiKeys(), $cacheableResponse->mimetype);
		logDebug('Caching Response as '.$key.' with mimetype '.$cacheableResponse->mimetype);
		$mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
		$mc->add($key, $cacheableResponse, false, PUELIA_CACHE_AGE);
	}
	
	public static function cacheURI($uri){
	    if(!function_exists("memcache_connect")) 
	        return false;
	    
	    logDebug('Caching '.$uri);
	    $key = LinkedDataApiCache::cacheKey($uri, '');
	    $mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
	    $mc->add($key, true, false );
	    return $key;
	}

	public static function hasCachedUri($uri){
	
	    logDebug("Looking in memcache for $uri");
	    if(!function_exists("memcache_connect")) 
	        return false;
	    
	    $mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
	    
	    $key = LinkedDataApiCache::cacheKey($uri, '');
	    $cachedObject = $mc->get($key);
	    if ($cachedObject)
	    {
	        return $cachedObject;
	    }
	    logDebug("No cached uri $uri");
	    return false;
	}
	
	
	public static function cacheConfig($filepath, ConfigGraph $configgraph){
		if(!function_exists("memcache_connect")) return false;
		
		logDebug('Caching '.$filepath);
		$key = LinkedDataApiCache::configCacheKey($filepath);
		$mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
		$mc->add($key, $configgraph, false );
		return $key;
	}

	public static function hasCachedConfig($filepath){
		
		logDebug("Looking in memcache for $filepath");	
		if(!function_exists("memcache_connect")) return false;
		$key = LinkedDataApiCache::configCacheKey($filepath);
		$mc = memcache_connect(PUELIA_MEMCACHE_HOST, PUELIA_MEMCACHE_PORT);
		$cachedObject = $mc->get($key);
		if ($cachedObject)
		{
			return $cachedObject;
		} 
		logDebug("No cached version of ConfigGraph from $filepath");
		return false;
	}

	public static function configCacheKey($filepath){
		$mtime = filemtime($filepath);	
		return md5($filepath.$mtime);
	}

	private static function cacheKey($requestUri, $mimetype)
	{
		$key  = $requestUri;
		if (isset($mimetype))
			$key .= trim($mimetype);
		return md5($key);
	}
	
}
