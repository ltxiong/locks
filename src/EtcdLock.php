<?php
namespace Ltxiong\Locks;

use Ltxiong\Locks\Tool;

/**
 * 基于ETCD的分布式锁实现，使用前，先搭建好etcd集群并启动相应服务
 * 
 * 可以参考：https://segmentfault.com/a/1190000021603215?utm_source=tag-newest
 * etcd api参数列表 参考： https://etcd.io/docs/v3.4.0/learning/api/#requests-and-responses
 * etcd api参数列表 参考： https://etcd.io/docs/v3.3.12/dev-guide/api_concurrency_reference_v3/
 * etcd api 参考： https://github.com/etcd-io/etcd/blob/master/etcdserver/etcdserverpb/rpc.proto
 * etcd api 参考： https://github.com/etcd-io/etcd/blob/master/etcdserver/api/v3lock/v3lockpb/v3lock.proto
 * 
 * 操作示例：
 * $etcd_lock = new EtcdLock("194.23.34.10", 13379);
 * //  采用kv_put方式加锁/解锁
 * $etcd_lease_id = time();
 * $lock_args['lock_key'] = "order:num";
 * $lock_args['lock_value'] = "202006081640";
 * $lock_args['lock_timeout'] = 2;
 * $lock_args['etcd_lease_id'] = $etcd_lease_id;
 * ////  采用lock_lock方式加锁/解锁
 * //$lock_args['lock_type'] = "lock_lock";
 * // 加锁
 * $lock_rs = $etcd_lock->getLock($lock_args);
 * //$release_lock_key = $lock_rs['lock_ext_data']['lock_key'];
 * // do some thing else
 * a();
 * // 释放锁
 * $release_lock_args['etcd_lease_id'] = $etcd_lease_id;
 * ////  采用lock_lock方式加锁/解锁
 * //$release_lock_args['lock_type'] = "lock_lock";
 * //$release_lock_args['lock_key'] = $release_lock_key;
 * 
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
     * 加锁/解锁方式
     *
     * @var array
     */
    private static $_etcd_lock_type = array(
        'kv_put',
        'lock_lock'
    );

    /**
     * 租约授权接口
     *
     * @var string
     */
    private static $_lease_grant_uri = "/v3/lease/grant";

    /**
     * 基于租约设置key-value接口
     *
     * @var string
     */
    private static $_kv_put_uri = "/v3/kv/put";

    /**
     * 租约释放/取消接口
     *
     * @var string
     */
    private static $_lease_revoke_uri = "/v3/lease/revoke";

    /**
     * 加锁接口
     *
     * @var string
     */
    private static $_lock_lock_uri = "/v3/lock/lock";

    /**
     * 释放/解锁接口
     *
     * @var string
     */
    private static $_lock_unlock_uri = "/v3/lock/unlock";

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
     * 加锁/获取锁，基于ETCD加锁，采用2步来实现，第一步，通过 lease/grant 申请设置一个带过期时间的租约，第二步，基于上面的租约来设置key-value结果
     * 特殊说明：传递参数时请严格按照要求传递参数，如加锁一直失败，请先检查传递参数是否符合条件
     *
     * @param array $lock_args 加锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_key']  type:string 加锁的key，例如：order:pay:lock
     *   $lock_args['lock_value']  type:string 用于加锁的具体值，例如：order-2020
     *   $lock_args['etcd_lease_id']  type:int 用于etcd加锁的具体值，可直接使用时间戳，整数，例如：1591600570
     * 非必需参数列表
     *   $lock_args['lock_timeout']  type:int 锁超时时间，单位为秒，例如：3，如不传默认值为1
     *   $lock_args['lock_type']  type:string 加锁方式，默认值为kv_put，目前支持2种，分别为：kv_put/lock_lock
     * 
     * @return array 加锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $lock_data_arr['lock_ok']  type:bool 加锁成功与否，true:成功，false:失败
     *   $lock_data_arr['error_msg']  type:string 加锁失败时，错误消息
     *   $lock_data_arr['lock_ext_data']  type:array 加锁成功，返回的额外参数列表，具体数据结构如下所示：
     *      $lock_ext_data['kv_put_revision']  type:string 采用租约形式创建key-value键值对进行加锁成功，返回的版本号
     *      $lock_ext_data['lock_key']  type:string 采用租约形式创建 name空间 加锁成功返回的 key 值，该值将用于主动释放锁
     * 
     */
    public function getLock(array $lock_args)
    {
        $lock_key = isset($lock_args['lock_key']) && $lock_args['lock_key'] ? $lock_args['lock_key'] : null;
        $lock_value = isset($lock_args['lock_value']) ? $lock_args['lock_value'] : 1;
        $lock_timeout = intval($lock_args['lock_timeout']);
        $etcd_lease_id = intval($lock_args['etcd_lease_id']);
        $lock_type = isset($lock_args['lock_type']) ? $lock_args['lock_type'] : 'kv_put';
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
        if(!in_array($lock_type, self::$_etcd_lock_type))
        {
            $lock_type = 'kv_put';
        }
        // 必需参数不满足加锁要求，直接返回加锁失败
        if(!($lock_key && $lock_value && $lock_timeout && $etcd_lease_id))
        {
            // 此处最好能够加一些日志输出和落地，加锁失败时，方便排查问题
            $lock_data_arr['error_msg'] = '10000:incorrect parameters';
            return $lock_data_arr;
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
            $lease_grant_rs = $http_tool->httpSender($protocol_header . self::$_lease_grant_uri, 'post', $lease_grant_args, $req_args);
            if($lease_grant_rs['http_code'] !== 200)
            {
                throw new Exception("10010:" . $lease_grant_rs['http_code'] . "--" . $lease_grant_rs['http_errno'] . "--" . $lease_grant_rs['http_error']);
            }
            $lease_grant_rs = $lease_grant_rs['data']['resp_rs'];
            // 创建租约失败，意味着加锁失败，直接返回
            if(!$this->etcdResponseOk($lease_grant_rs))
            {
                throw new Exception("10020:" . $lease_grant_rs['message']);
            }
            $lock_ext_data = array();
            if($lock_type === 'kv_put')
            {
                $kv_put_args = array('key' => base64_encode($lock_key), 'value' => base64_encode($lock_value), 'lease' => $etcd_lease_id);
                $kv_put_rs = $http_tool->httpSender($protocol_header . self::$_kv_put_uri, 'post', $kv_put_args, $req_args);
                if($kv_put_rs['http_code'] !== 200)
                {
                    throw new Exception("10030:" . $kv_put_rs['http_code'] . "--" . $kv_put_rs['http_errno'] . "--" . $kv_put_rs['http_error']);
                }
                $kv_put_rs = $kv_put_rs['data']['resp_rs'];
                // 带租约信息创建一个key-value 键值对，如创建失败，意味着加锁失败，直接返回
                if(!$this->etcdResponseOk($kv_put_rs))
                {
                    throw new Exception("10040:" . $kv_put_rs['message']);
                }
                $lock_ext_data = array("kv_put_revision" => $kv_put_rs['header']['revision']);
            }
            else
            {
                $lock_lock_args = array('name' => base64_encode($lock_key), 'lease' => $etcd_lease_id);
                $lock_lock_rs = $http_tool->httpSender($protocol_header . self::$_lock_lock_uri, 'post', $lock_lock_args, $req_args);
                if($lock_lock_rs['http_code'] !== 200)
                {
                    throw new Exception("10030:" . $lock_lock_rs['http_code'] . "--" . $lock_lock_rs['http_errno'] . "--" . $lock_lock_rs['http_error']);
                }
                $lock_lock_rs = $lock_lock_rs['data']['resp_rs'];
                // 带租约信息创建一个 name 空间的锁，如创建失败，意味着加锁失败，直接返回
                if(!$this->etcdResponseOk($lock_lock_rs))
                {
                    throw new Exception("10040:" . $lock_lock_rs['message']);
                }
                $lock_ext_data = array("lock_key" => $lock_lock_rs['key']);
            }
            // 加锁成功，将成功的结果赋值处理
            $lock_data_arr['lock_ok'] = true;
            $lock_data_arr['lock_ext_data'] = $lock_ext_data;
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
     *
     * @param array $lock_args 释放锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_type']  type:string 解锁方式，默认值为kv_put，目前支持2种，分别为：kv_put/lock_lock，要与加锁的方式完全一致才行
     *   $lock_args['etcd_lease_id']  type:int lock_type为 kv_put时用，用于etcd解锁的租约ID具体值，可直接使用时间戳，整数，例如：1591600570
     *   $lock_args['lock_key']  type:string lock_type为 lock_lock时用，采用租约形式创建 name空间 加锁成功返回的 key 值，该值将用于主动释放锁
     * 
     * @return array 解锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $release_lock_data_arr['release_lock_ok']  type:bool 解锁成功与否，true:成功，false:失败
     *   $release_lock_data_arr['error_msg']  type:string 解锁失败时，错误消息
     *   $release_lock_data_arr['release_lock_ext_data']  type:array 解锁成功，返回的额外参数列表，如无额外参数列表，返回空数组
     * 
     */
    public function releaseLock(array $lock_args)
    {
        $lock_key = $lock_args['lock_key'];
        $etcd_lease_id = intval($lock_args['etcd_lease_id']);
        $lock_type = isset($lock_args['lock_type']) ? $lock_args['lock_type'] : 'kv_put';
        if(!in_array($lock_type, self::$_etcd_lock_type))
        {
            $lock_type = 'kv_put';
        }
        $release_lock_data_arr = array(
            'release_lock_ok' => false,
            'error_msg' => '',
            'release_lock_ext_data' => ''
        );
        // 必需参数不满足释放锁要求，直接返回失败
        if(!(($lock_type === 'kv_put' && $etcd_lease_id) || ($lock_type === 'lock_lock' && $lock_key)))
        {
            // 此处最好能够加一些日志输出和落地，释放锁失败时， 方便排查问题
            $release_lock_data_arr['error_msg'] = '20000:incorrect parameters';;
            return $release_lock_data_arr;
        }
        $http_tool = new Tool();
        // 请求响应返回的数据格式
        $req_args['return_json'] = 1;
        // http请求超时时间
        $req_args['time_out'] = 2;
        try
        {
            $protocol_header = $this->getProtocolHeader();
            if($lock_type === 'kv_put')
            {
                // 自定义一个租约ID，申请一个带过期时间的租约
                $lease_revoke_args = array("ID" => $etcd_lease_id);
                $lease_revoke_rs = $http_tool->httpSender($protocol_header . self::$_lease_revoke_uri, 'post', $lease_revoke_args, $req_args);
                if($lease_revoke_rs['http_code'] !== 200)
                {
                    throw new Exception("20010:" . $lease_revoke_rs['http_code'] . "--" . $lease_revoke_rs['http_errno'] . "--" . $lease_revoke_rs['http_error']);
                }
                $lease_revoke_rs = $lease_revoke_rs['data']['resp_rs'];
                // 释放租约失败，意味着解锁失败，直接返回
                if(!$this->etcdResponseOk($lease_revoke_rs))
                {
                    // 如返回的错误 为 lease无法找到，说明相应的 lease 已自动 过期，此时也可表示 解锁成功
                    // 如返回的错误消息不是此类型，说明是其它错误，表示该 lease 可能还没被释放或过期
                    if(!($lease_revoke_rs['message'] && stripos($lease_revoke_rs['message'], 'lease not found') === false))
                    {
                        throw new Exception("20020:" . $lease_revoke_rs['message']);
                    }
                }
            }
            else
            {
                // 自定义一个租约ID，申请一个带过期时间的租约
                $lock_unlock_args = array("key" => $lock_key);
                $lock_unlock_rs = $http_tool->httpSender($protocol_header . self::$_lock_unlock_uri, 'post', $lock_unlock_args, $req_args);
                if($lock_unlock_rs['http_code'] !== 200)
                {
                    throw new Exception("20010:" . $lock_unlock_rs['http_code'] . "--" . $lock_unlock_rs['http_errno'] . "--" . $lock_unlock_rs['http_error']);
                }
                $lock_unlock_rs = $lock_unlock_rs['data']['resp_rs'];
                // 通过锁机制释放锁，假设锁不存在，并不会报错，照样会返回正常的响应结果
                // 释放锁失败，意味着解锁失败，直接返回
                if(!$this->etcdResponseOk($lock_unlock_rs))
                {
                    throw new Exception("20020:" . $lock_unlock_rs['message']);
                }
            }
            $release_lock_data_arr['release_lock_ok'] = true;
        }
        catch(Exception $e)
        {
            // 此处最好能够加一些日志，记录错误输出，方便排查解锁失败问题
            $release_lock_data_arr['error_msg'] = $e->getMessage();
        }
        return $release_lock_data_arr;
    }
}
