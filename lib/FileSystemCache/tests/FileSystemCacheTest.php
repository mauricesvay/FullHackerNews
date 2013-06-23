<?php
require_once __DIR__."/../lib/FileSystemCache.php";
FileSystemCache::$cacheDir = __DIR__."/cache";

class FileSystemCacheTest extends PHPUnit_Framework_TestCase {  
    /**
     * @dataProvider keyDataProvider
     */
    function testGenerateKey($key_data, $group) {
        $key = FileSystemCache::generateCacheKey($key_data, $group);
        
        $this->assertInstanceOf('FileSystemCacheKey', $key);
    }
    
    /**
     * @dataProvider dataProvider
     */
    function testStoreDataTypes($data) {
        $key = FileSystemCache::generateCacheKey('mytestkey');
        
        FileSystemCache::invalidate($key);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
        
        FileSystemCache::store($key, $data);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        FileSystemCache::invalidate($key);
        
        $this->assertFalse(FileSystemCache::retrieve($key));        
    }
      
    /**
     * @dataProvider keyDataProvider
     */
    function testStore($key_data, $group) {  
        $key = FileSystemCache::generateCacheKey($key_data, $group);
        
        $data = 'test'.microtime(true);
        
        FileSystemCache::invalidate($key);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
        
        FileSystemCache::store($key, $data);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        FileSystemCache::invalidate($key);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testStoreTtl() {  
        $key = FileSystemCache::generateCacheKey('ttl test');  
        $data = 'test ttl '.microtime(true);
        
        FileSystemCache::invalidate($key);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
        
        FileSystemCache::store($key, $data, 1);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        sleep(2);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testRetrieveNewerThan() {
        $key = FileSystemCache::generateCacheKey('newer than test');
        $data = 'test newer than data';
        FileSystemCache::store($key, $data);
        
        $this->assertFalse(FileSystemCache::retrieve($key, time() + 5));
        $this->assertEquals($data, FileSystemCache::retrieve($key, time() - 5));
        
        FileSystemCache::invalidate($key);
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testGetAndModifyReturnFalse() {
        $key = FileSystemCache::generateCacheKey('get and modify key');
        $data = 'get and modify data';
        
        FileSystemCache::store($key, $data, 1);
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        FileSystemCache::getAndModify($key, function($value) {
            return false;
        });
        
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testGetAndModify() {
        $key = FileSystemCache::generateCacheKey('get and modify key');
        $data = 'get and modify data';
        
        FileSystemCache::store($key, $data, 1);
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        FileSystemCache::getAndModify($key, function($value) {
            $value .= 'test';
            return $value;
        });
        
        $this->assertEquals($data.'test', FileSystemCache::retrieve($key));
        
        sleep(2);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testGetAndModifyResetTtl() {
        $key = FileSystemCache::generateCacheKey('get and modify reset ttl key');
        $data = 'get and modify reset ttl data';
        
        FileSystemCache::store($key, $data, 3);
        sleep(2);
        // At this point, the key expires in 1 seconds
        $this->assertEquals($data, FileSystemCache::retrieve($key));
        
        FileSystemCache::getAndModify($key, function($value) {
            $value .= 'test';
            return $value;
        }, true);
        
        sleep(2);
        
        // The original expiration has hit, but getAndModify should have extended it
        $this->assertEquals($data.'test', FileSystemCache::retrieve($key));
        
        sleep(2);
        
        $this->assertFalse(FileSystemCache::retrieve($key));
    }
    
    function testGetAndModifyUnchanged() {
        $key = FileSystemCache::generateCacheKey('get and modify unchanged');
        $data = 'get and modify unchanged';
        
        FileSystemCache::store($key, $data);
        
        $return = FileSystemCache::getAndModify($key, function($value) {
            return $value;
        });
        
        $this->assertEquals($data, $return);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key));
    }
    
    /**
     * @expectedException Exception
     */
    function testHackedGroupInvalidation() {
        FileSystemCache::invalidateGroup('this/../../is/a/hack');
    }
    
    function testGroupInvalidation() {
        $key_root = FileSystemCache::generateCacheKey('mykey');
        $key_group1 = FileSystemCache::generateCacheKey('mykey1','test');
        $key_group2 = FileSystemCache::generateCacheKey('mykey2','test');
        $key_sub = FileSystemCache::generateCacheKey('mykey','test/test');
        $key_other = FileSystemCache::generateCacheKey('mykey','test2');
        
        $data = 'group invalidation';
        
        FileSystemCache::store($key_root, $data);
        FileSystemCache::store($key_group1, $data);
        FileSystemCache::store($key_group2, $data);
        FileSystemCache::store($key_sub, $data);
        FileSystemCache::store($key_other, $data);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key_root));
        $this->assertEquals($data, FileSystemCache::retrieve($key_group1));
        $this->assertEquals($data, FileSystemCache::retrieve($key_group2));
        $this->assertEquals($data, FileSystemCache::retrieve($key_sub));
        $this->assertEquals($data, FileSystemCache::retrieve($key_other));
        
        FileSystemCache::invalidateGroup('test', false);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key_root));
        $this->assertFalse(FileSystemCache::retrieve($key_group1));
        $this->assertFalse(FileSystemCache::retrieve($key_group2));
        $this->assertEquals($data, FileSystemCache::retrieve($key_sub));
        $this->assertEquals($data, FileSystemCache::retrieve($key_other));
        
        FileSystemCache::invalidate($key_root);
        FileSystemCache::invalidate($key_sub);
        FileSystemCache::invalidate($key_other);
        
        $this->assertFalse(FileSystemCache::retrieve($key_root));
        $this->assertFalse(FileSystemCache::retrieve($key_sub));
        $this->assertFalse(FileSystemCache::retrieve($key_other));
    }
    
    
    function testGroupInvalidationRecursive() {
        $key_root = FileSystemCache::generateCacheKey('mykey');
        $key_group1 = FileSystemCache::generateCacheKey('mykey1','test');
        $key_group2 = FileSystemCache::generateCacheKey('mykey2','test');
        $key_sub = FileSystemCache::generateCacheKey('mykey','test/test');
        $key_other = FileSystemCache::generateCacheKey('mykey','test2');
        
        $data = 'group invalidation recursive';
        
        FileSystemCache::store($key_root, $data);
        FileSystemCache::store($key_group1, $data);
        FileSystemCache::store($key_group2, $data);
        FileSystemCache::store($key_sub, $data);
        FileSystemCache::store($key_other, $data);
        
        $this->assertEquals($data, FileSystemCache::retrieve($key_root));
        $this->assertEquals($data, FileSystemCache::retrieve($key_group1));
        $this->assertEquals($data, FileSystemCache::retrieve($key_group2));
        $this->assertEquals($data, FileSystemCache::retrieve($key_sub));
        $this->assertEquals($data, FileSystemCache::retrieve($key_other));
        
        FileSystemCache::invalidateGroup('test');
        
        $this->assertEquals($data, FileSystemCache::retrieve($key_root));
        $this->assertFalse(FileSystemCache::retrieve($key_group1));
        $this->assertFalse(FileSystemCache::retrieve($key_group2));
        $this->assertFalse(FileSystemCache::retrieve($key_sub));
        $this->assertEquals($data, FileSystemCache::retrieve($key_other));
        
        FileSystemCache::invalidate($key_root);
        FileSystemCache::invalidate($key_other);
        
        $this->assertFalse(FileSystemCache::retrieve($key_root));
        $this->assertFalse(FileSystemCache::retrieve($key_other));
    }
    
    function keyProvider() {
        return array(
            array(FileSystemCache::generateCacheKey('mykey')),
            array(FileSystemCache::generateCacheKey('mykey','test')),
            array(FileSystemCache::generateCacheKey('mykey','test/test')),
        );
    }
    
    function keyDataProvider() {
        $data = $this->dataProvider();
        $groups = $this->groupProvider();
        
        $keys = array();
        foreach($data as $key_data) {
            foreach($groups as $group) {
                $keys[] = array(
                    $key_data[0],
                    $group[0]
                );
            }
        }
        
        return $keys;
    }
    function dataProvider() {
        $temp = new DateTime();
        
        return array(
            array(99),
            array('string'),
            array(array('an','array','with'=>'data')),
            array( $temp )
        );
    }
    function groupProvider() {
        return array(
            array(null),
            array('test'),
            array('test/test')
        ); 
    }
}
