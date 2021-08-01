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
     * 构造函数，实例化时需传入 Redis实例，Redis实例必需为单实例而不是集群或主从(主从同时对外提供服务)
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
     * @return array 加锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $lock_data_arr['lock_ok']  type:bool 加锁成功与否，true:成功，false:失败
     *   $lock_data_arr['error_msg']  type:string 加锁失败时，错误消息
     *   $lock_data_arr['lock_ext_data']  type:array 加锁成功，返回的额外参数列表，如无额外参数列表，返回空数组
     * 
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
        $lock_data_arr = array(
            'lock_ok' => false,
            'error_msg' => '',
            'lock_ext_data' => array()
        );
        // 必需参数不满足加锁要求，直接返回加锁失败
        if(!($lock_key && $lock_value && $lock_timeout))
        {
            // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
            $lock_data_arr['error_msg'] = '10000:incorrect parameters';
            return $lock_data_arr;
        }
        try
        {
            $lock_ok = $this->_rds_conn->set($lock_key, $lock_value, array('nx', 'ex' => $lock_timeout));
            if(!is_bool($lock_ok))
            {
                throw new Exception("10010: redis rs error");
            }
            // 加锁成功，将成功的结果赋值处理
            $lock_data_arr['lock_ok'] = $lock_ok;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题
            $lock_data_arr['error_msg'] = $e->getMessage();
        }
        return $lock_data_arr;
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
     * @return array 解锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $release_lock_data_arr['release_lock_ok']  type:bool 解锁成功与否，true:成功，false:失败
     *   $release_lock_data_arr['error_msg']  type:string 解锁失败时，错误消息
     *   $release_lock_data_arr['release_lock_ext_data']  type:array 解锁成功，返回的额外参数列表，如无额外参数列表，返回空数组
     * 
     */
    public function releaseLock(array $lock_args)
    {
        $lock_key = isset($lock_args['lock_key']) && $lock_args['lock_key'] ? $lock_args['lock_key'] : null;
        $lock_value = isset($lock_args['lock_value']) ? $lock_args['lock_value'] : '';
        $release_lock_data_arr = array(
            'release_lock_ok' => false,
            'error_msg' => '',
            'release_lock_ext_data' => ''
        );
        // 必需参数不满足释放锁要求，直接返回失败
        if(!($lock_key && $lock_value))
        {
            // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
            $release_lock_data_arr['error_msg'] = '10000:incorrect parameters';
            return $release_lock_data_arr;
        }
        // 释放锁时，要注意一下，只有锁的key value完全相等时才进行释放
        try
        {
            // 执行lua脚本释放锁，确保原子性操作
            $script = 'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end';
            $delRs = $this->_rds_conn->eval($script, [$lock_key, $lock_value], 1);
            $delRs = intval($delRs);
            $release_lock_data_arr['release_lock_ok'] = $delRs > 0 ? true : false;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题
            $release_lock_data_arr['error_msg'] = $e->getMessage();
        }
        return $release_lock_data_arr;
        
    }
}

