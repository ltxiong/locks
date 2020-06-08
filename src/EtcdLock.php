<?php
namespace Ltxiong\Locks;


use Ltxiong\Locks\Tool;

/**
 * 基于ETCD的分布式锁实现
 * 可以参考：https://segmentfault.com/a/1190000021603215?utm_source=tag-newest
 * etcd api 参考： https://etcd.io/docs/v3.4.0/learning/api/#requests-and-responses
 * etcd api 参考： https://github.com/etcd-io/etcd/blob/master/etcdserver/etcdserverpb/rpc.proto
 * 
 * 操作示例：
 * $etcd_lock = new EtcdLock("194.23.34.10", 13379);
 * $etcd_lease_id = time();
 * $lock_args['lock_key'] = "order:num";
 * $lock_args['lock_value'] = "202006081640";
 * $lock_args['lock_timeout'] = 2;
 * $lock_args['etcd_lease_id'] = $etcd_lease_id;
 * // 加锁
 * $lock_rs = $etcd_lock->getLock($lock_args);
 * 
 * // do some thing else
 * a();
 * 
 * $release_lock_args['etcd_lease_id'] = $etcd_lease_id;
 * // 释放锁
 * $release_lock_rs = $etcd_lock->releaseLock($release_lock_args);
 * 
 */

class EtcdLock implements Locks
{

    /**
     * Etcd host
     *
     * @var [type] string
     */
    private $_etcd_host = null;

    /**
     * Etcd 端口
     *
     * @var [type] int
     */
    private $_etcd_port = null;

    /**
     * 租约授权接口
     *
     * @var string
     */
    private $_lease_grant_uri = "/v3/lease/grant";

    /**
     * 基于租约设置key-value接口
     *
     * @var string
     */
    private $_kv_put_uri = "/v3/kv/put";

    /**
     * 租约释放/取消接口
     *
     * @var string
     */
    private $_lease_revoke_uri = "/v3/lease/revoke";

    /**
     * 构造函数，
     *
     * @param [type] $etcd_host Etcd host，可以为域名
     * @param [type] $etcd_port Etcd port，如host为域名时，该参数可不传或者根据实际需要传递，非80端口一定要传
     */
    public function __construct($etcd_host, $etcd_port = null)
    {
        $this->_etcd_host = $etcd_host;
        // 如是直接使用域名，相应的port参数可缺失
        $this->_etcd_port = $etcd_port;
    }

    /**
     * 组装Etcd请求uri
     *
     * @return string uri 根据 etcd host port 组装http请求协议url头
     */
    private function getProtocolHeader()
    {
        $uri = "http://" . $this->_etcd_host;
        if($this->_etcd_port)
        {
            $uri .= ":" . $this->_etcd_port;
        }
        return $uri;
    }

    /**
     * 检查响应结果是否为正常的响应结果
     *
     * @param array $etcd_data_arr 响应结果数组
     * @return boole 
     */
    private function etcdResponseOk($etcd_data_arr)
    {
        return array_key_exists('header', $etcd_data_arr);
    }

    /**
     * 加锁/获取锁
     * 特殊说明：传递参数时请严格按照要求传递参数，如加锁一直失败，请先检查传递参数是否符合条件
     *
     * @param array $lock_args 加锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_key']  type:string 加锁的key，例如：order:pay:lock
     *   $lock_args['lock_value']  type:string 用于加锁的具体值，例如：order-2020
     *   $lock_args['etcd_lease_id']  type:int 用于etcd加锁的具体值，可直接使用时间戳，整数，例如：1591600570
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
        $etcd_lease_id = intval($lock_args['etcd_lease_id']);
        // 万一没设置过期时间，可以给一个默认值
        if (empty($lock_timeout))
        {
            $lock_timeout = 1;
        }
        $lock_ok = false;
        // 必需参数不满足加锁要求，直接返回加锁失败
        if(!($lock_key && $lock_value && $lock_timeout && $etcd_lease_id))
        {
            // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
            return 110;
            return $lock_ok;
        }
        $http_tool = new Tool();
        // 请求响应返回的数据格式
        $req_args['return_json'] = 1;
        // http请求超时时间
        $req_args['time_out'] = 2;

        try
        {
            $protocol_header = $this->getProtocolHeader();
            // 自定义一个租约ID，申请一个带过期时间的租约
            $lease_grant_args = array(
                "TTL" => $lock_timeout,
                "ID" => $etcd_lease_id
            );
            $lease_grant_rs = $http_tool->httpSender($protocol_header . $this->_lease_grant_uri, 'post', $lease_grant_args, $req_args);
            if($lease_grant_rs['http_code'] !== 200)
            {
                // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
                throw new Exception($lease_grant_rs['http_code'] . "--" . $lease_grant_rs['http_errno'] . "--" . $lease_grant_rs['http_error']);
            }

            $lease_grant_rs = $lease_grant_rs['data']['resp_rs'];
            // 创建租约失败，意味着加锁失败，直接返回
            if(!$this->etcdResponseOk($lease_grant_rs))
            {
                // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
                throw new Exception($lease_grant_rs['message']);
            }
            $kv_put_args = array(
                'key' => base64_encode($lock_key),
                'value' => base64_encode($lock_value),
                'lease' => $etcd_lease_id
            );
            $kv_put_rs = $http_tool->httpSender($protocol_header . $this->_kv_put_uri, 'post', $kv_put_args, $req_args);
            if($kv_put_rs['http_code'] !== 200)
            {
                // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
                throw new Exception($kv_put_rs['http_code'] . "--" . $kv_put_rs['http_errno'] . "--" . $kv_put_rs['http_error']);
            }
            $kv_put_rs = $kv_put_rs['data']['resp_rs'];
            // 带租约信息创建一个key-value 键值对，如创建失败，意味着加锁失败，直接返回
            if(!$this->etcdResponseOk($kv_put_rs))
            {
                // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
                throw new Exception($kv_put_rs['message']);
            }
            $kv_put_revision = intval($kv_put_rs['revision']);
            $lock_ok = true;
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
     *
     * @param array $lock_args 释放锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['etcd_lease_id']  type:int 用于etcd解锁的租约ID具体值，可直接使用时间戳，整数，例如：1591600570
     * 
     * @return int 锁释放成功数量
     */
    public function releaseLock(array $lock_args)
    {
        $etcd_lease_id = intval($lock_args['etcd_lease_id']);
        // 锁释放成功数量 
        $release_ok_num = 0;
        // 必需参数不满足释放锁要求，直接返回失败
        if(!$etcd_lease_id)
        {
            // 此处最好能够加一些日志输出和落地，释放锁失败时， 方便排查问题
            return $release_ok_num;
        }
        $http_tool = new Tool();
        // 请求响应返回的数据格式
        $req_args['return_json'] = 1;
        // http请求超时时间
        $req_args['time_out'] = 2;
        try
        {
            $protocol_header = $this->getProtocolHeader();
            // 自定义一个租约ID，申请一个带过期时间的租约
            $lease_revoke_args = array(
                "ID" => $etcd_lease_id
            );
            $lease_revoke_rs = $http_tool->httpSender($protocol_header . $this->_lease_revoke_uri, 'post', $lease_revoke_args, $req_args);
            if($lease_revoke_rs['http_code'] !== 200)
            {
                // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
                throw new Exception($lease_revoke_rs['http_code'] . "--" . $lease_revoke_rs['http_errno'] . "--" . $lease_revoke_rs['http_error']);
            }
            $lease_revoke_rs = $lease_revoke_rs['data']['resp_rs'];
            // 释放租约失败，意味着解锁失败，直接返回
            if(!$this->etcdResponseOk($lease_revoke_rs))
            {
                // 此处最好能够加一些日志输出和落地，解锁失败时，方便排查问题
                throw new Exception($lease_revoke_rs['message']);
            }
            $release_ok_num = 1;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查解锁失败问题
            
        }
        return $release_ok_num;
    }
}
