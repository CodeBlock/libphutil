<?php

/**
 * Interface to a disk cache. Storage persists across requests.
 *
 * This cache is very slow compared to caches like APC. It is intended as a
 * specialized alternative to APC when APC is not available.
 *
 * This is a highly specialized cache and not appropriate for use as a
 * generalized key-value cache for arbitrary application data.
 *
 * Also note that reading and writing keys from the cache currently involves
 * loading and saving the entire cache, no matter how little data you touch.
 *
 * @task  kvimpl    Key-Value Cache Implementation
 * @task  storage   Cache Storage
 * @group cache
 */
final class PhutilKeyValueCacheOnDisk extends PhutilKeyValueCache {

  private $cache = array();
  private $cacheFile;
  private $lock;


/* -(  Key-Value Cache Implementation  )------------------------------------- */


  public function isAvailable() {
    return true;
  }


  public function getKeys(array $keys) {
    $call_id = null;
    if ($this->getProfiler()) {
      $call_id = $this->getProfiler()->beginServiceCall(
        array(
          'type' => 'kvcache-get',
          'name' => 'disk',
          'keys' => $keys,
        ));
    }

    $now = time();

    $results = array();
    $reloaded = false;
    foreach ($keys as $key) {

      // Try to read the value from cache. If we miss, load (or reload) the
      // cache.

      while (true) {
        if (isset($this->cache[$key])) {
          $val = $this->cache[$key];
          if (empty($val['ttl']) || $val['ttl'] >= $now) {
            $results[$key] = $val['val'];
            break;
          }
        }

        if ($reloaded) {
          break;
        }

        $this->loadCache($hold_lock = false);
        $reloaded = true;
      }
    }

    if ($call_id) {
      $this->getProfiler()->endServiceCall(
        $call_id,
        array(
          'hits' => array_keys($results),
        ));
    }

    return $results;
  }


  public function setKeys(array $keys, $ttl = null) {
    $call_id = null;

    if ($ttl) {
      $ttl_epoch = time() + $ttl;
    } else {
      $ttl_epoch = null;
    }

    $dicts = array();
    foreach ($keys as $key => $value) {
      $dict = array(
        'val' => $value,
      );
      if ($ttl_epoch) {
        $dict['ttl'] = $ttl_epoch;
      }
      $dicts[$key] = $dict;
    }

    if ($this->getProfiler()) {
      $call_id = $this->getProfiler()->beginServiceCall(
        array(
          'type' => 'kvcache-set',
          'name' => 'disk',
          'keys' => array_keys($keys),
          'ttl'  => $ttl,
        ));
    }

    $this->loadCache($hold_lock = true);
    foreach ($dicts as $key => $dict) {
      $this->cache[$key] = $dict;
    }
    $this->saveCache();

    if ($call_id) {
      $this->getProfiler()->endServiceCall($call_id, array());
    }

    return $this;
  }


  public function deleteKeys(array $keys) {
    $call_id = null;
    if ($this->getProfiler()) {
      $call_id = $this->getProfiler()->beginServiceCall(
        array(
          'type' => 'kvcache-del',
          'name' => 'disk',
          'keys' => $keys,
        ));
    }

    $this->loadCache($hold_lock = true);
    foreach ($keys as $key) {
      unset($this->cache[$key]);
    }
    $this->saveCache();

    if ($call_id) {
      $this->getProfiler()->endServiceCall($call_id, array());
    }

    return $this;
  }


  public function destroyCache() {
    Filesystem::remove($this->getCacheFile());
    return $this;
  }


/* -(  Cache Storage  )------------------------------------------------------ */


  /**
   * @task storage
   */
  public function setCacheFile($file) {
    $this->cacheFile = $file;
    return $this;
  }


  /**
   * @task storage
   */
  private function loadCache($hold_lock) {
    if ($this->lock) {
      throw new Exception('Trying to loadCache() with a lock!');
    }

    $lock = PhutilFileLock::newForPath($this->getCacheFile().'.lock');
    $lock->lock();

    try {
      $this->cache = array();
      if (Filesystem::pathExists($this->getCacheFile())) {
        $cache = unserialize(Filesystem::readFile($this->getCacheFile()));
        if ($cache) {
          $this->cache = $cache;
        }
      }
    } catch (Exception $ex) {
      $lock->unlock();
      throw $ex;
    }

    if ($hold_lock) {
      $this->lock = $lock;
    } else {
      $lock->unlock();
    }
  }


  /**
   * @task storage
   */
  private function saveCache() {
    if (!$this->lock) {
      throw new Exception(
        'Call loadCache($hold_lock=true) before saveCache()!');
    }

    // We're holding a lock so we're safe to do a write to a well-known file.
    // Write to the same directory as the cache so the rename won't imply a
    // copy across volumes.
    $new = $this->getCacheFile().'.new';
    Filesystem::writeFile($new, serialize($this->cache));
    rename($new, $this->getCacheFile());

    $this->lock->unlock();
    $this->lock = null;
  }


  /**
   * @task storage
   */
  private function getCacheFile() {
    if (!$this->cacheFile) {
      throw new Exception("Call setCacheFile() before using a disk cache!");
    }
    return $this->cacheFile;
  }

}
