<?php
namespace Ltxiong\Locks;


/**
 * 基于Redis的分布式锁实现，Redis版本>=2.8，2.8以下无法直接使用此加锁方案
 * 
 * 操作示例：
 * $redis_conn = new Redis();
 * $redis_conn->connect('127.0.0.1', 6379); // 连接Redis
 * $lock_key = "test:lock:order:2020:06"; // 用于加锁的key
 * $lock_value = time(); // 用于加锁的value
 * $lock_args['lock_key'] = $lock_key;
 * $lock_args['lock_value'] = "$lock_value";
 * $lock_args['lock_timeout'] = 5; // 锁释放的超时时间 
 * $r_lock = new RedisLock($redis_conn);  // 实例化加锁类
 * $lock_rs = $lock->getLock($lock_args); // 加锁
 * // 中间做自己的其它事情
 * $redis_conn->set('rd_01', "hy_rd_01..$q.......", 3600);
 * $redis_conn->get('rd_01');
 * $release_lock_rs = $lock->releaseLock($lock_args); // 释放锁
 * 
 */
class RedisLock implements Locks
{

    /**
     * Redis实例连接
     *
     * @var [type] Redis-connection 
     */
    private $_rds_conn = null;

    /**
     * 构造函数，实例化时需传入 Redis实例
     *
     * @param [type] $redis_conn Redis实例
     */
    public function __construct($redis_conn)
    {
        $this->_rds_conn = $redis_conn;
    }

    /**
     * 加锁/获取锁
     * 特殊说明：传递参数时请严格按照要求传递参数，如加锁一直失败，请先检查传递参数是否符合条件
     *
     * @param array $lock_args 加锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_key']  type:string 加锁的key，例如：order:pay:lock
     *   $lock_args['lock_value']  type:string 用于加锁的具体值，例如：order-2020
     * 非必需参数列表
     *   $lock_args['lock_timeout']  type:int 锁超时时间，单位为秒，例如：3，如不传默认值为1
     * 
     * @return bool 加锁成功与否
     */
    public function getLock(array $lock_args)
    {
        $lock_key = isset($lock_args['lock_key']) && $lock_args['lock_key'] ? $lock_args['lock_key'] : null;
        $lock_value = isset($lock_args['lock_value']) ? $lock_args['lock_value'] : 1;
        $lock_timeout = intval($lock_args['lock_timeout']);
        // 万一没设置过期时间，可以给一个默认值
        if (empty($lock_timeout))
        {
            $lock_timeout = 1;
        }
        $lock_ok = false;
        // 必需参数不满足加锁要求，直接返回加锁失败
        if(!($lock_key && $lock_value && $lock_timeout))
        {
            // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
            return $lock_ok;
        }
        try
        {
            $lock_ok = $this->_rds_conn->set($lock_key, $lock_value, array('nx', 'ex' => $lock_timeout)); 
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题

        }
        return $lock_ok;
    }

    /**
     * 释放锁
     * 特殊说明：传递参数时请严格按照要求传递参数，如释放锁一直失败，请先检查传递参数是否符合条件
     * 释放锁时，要注意一下，只有锁的key value完全相等时才进行释放，否则不做锁的释放
     *
     * @param array $lock_args 释放锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_key']  type:string 加锁的key，例如：order:pay:lock
     *   $lock_args['lock_value']  type:string 用于加锁的具体值，例如：order-2020
     * 
     * @return int 锁释放成功数量
     */
    public function releaseLock(array $lock_args)
    {
        $lock_key = isset($lock_args['lock_key']) && $lock_args['lock_key'] ? $lock_args['lock_key'] : null;
        $lock_value = isset($lock_args['lock_value']) ? $lock_args['lock_value'] : null;
        // 锁释放成功数量 
        $release_ok_num = 0;
        // 必需参数不满足释放锁要求，直接返回失败
        if(!($lock_key && $lock_value))
        {
            // 此处最好能够加一些日志输出和落地，释放锁失败时， 方便排查问题
            return $release_ok_num;
        }
        // 释放锁时，要注意一下，只有锁的key value完全相等时才进行释放
        try
        {
            // 当前从缓存当中获取到的值最好记录一下日志，方便问题排查
            $current_cache_value = $this->_rds_conn->get($lock_key);
            if($lock_value === $current_cache_value)
            {
                $release_ok_num = $this->_rds_conn->delete($lock_key);
            }
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题
            
        }
        return $release_ok_num;
        
    }
}

