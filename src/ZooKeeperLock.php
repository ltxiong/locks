<?php
namespace Ltxiong\Locks;


/**
 * 基于Zookeeper 的分布式锁实现
 * 可以参考：https://www.php.net/manual/zh/zookeeper.create.php
 * 
 * 操作示例：
 * $zk_lock_root_node = "/data/kan";
 * $zk_server = 'ltx_zkp_01:2181,ltx_zkp_02:2181,ltx_zkp_03:2181';
 * $zk_lock = new ZooKeeperLock($zk_server, $zk_lock_root_node);
 * $lock_args = array(
 *     'lock_key' => "k_order_",
 *     'lock_value' => time()
 * );
 * // 加锁
 * $lock_rs = $zk_lock->getLock($lock_args);
 * var_dump($lock_rs);
 * // 处理具体业务
 * doSomething();
 * // 释放锁
 * $lock_args['release_node'] = $lock_rs['lock_ext_data']['lock_node'];
 * $unlock_rs = $zk_lock->releaseLock($lock_args);
 * // $unlock_rs['release_lock_ok'] 如果为false，需要根据业务实际情况和错误情况，进行必要的处理，例如，重试或者类似的操作
 * var_dump($unlock_rs);
 * 
 */

class ZooKeeperLock implements Locks
{

    /**
     * zookeeper 实例
     *
     * @var string
     */
    private static $_my_zk;
    /**
     * 我自己的节点
     *
     * @var string
     */
    private static $_my_node;
    /**
     * 异步通知
     *
     * @var bool
     */
    private static $_is_notifyed;
    /**
     * zookeeper 加锁时root节点
     *
     * @var string
     */
    private static $root;

    /**
     * The initial ACL of the node. The ACL must not be null or empty
     *
     * @var string
     */
    private $_acl_arr = array(
        array(
            'perms'  => Zookeeper::PERM_ALL,
            'scheme' => 'world',
            'id'     => 'anyone',
        )
    );

    /**
     * 单次监听最大次数，与超时时间等同，假设每次等待时间为100ms，超时次数20次相当于设置了2秒超时
     *
     * @var integer
     */
    private static $_max_wait_time = 20;

    /**
     * 构造函数
     *
     * @param string $zk_server_str zookeeper host string，例如：'ltx_zkp_01:2181,ltx_zkp_02:2181,ltx_zkp_03:2181';
     * @param string $root 加锁的根节点
     */
    public function __construct(string $zk_server_str, string $root)
    {
        self::$root = $root;
        if(!isset(self::$_my_zk))
        {
            try
            {
                $zk = new Zookeeper($zk_server_str);
                if(!$zk)
                {
                    throw new Exception('connect zookeeper error');
                }
                self::$_my_zk = $zk;
            }
            catch (Exception $e)
            {
                // 此处最好能够加一些日志，记录错误输出，方便排查加锁失败问题
                echo $e->getMessage();
            }
        }
    }

    /**
     * 加锁/获取锁，基于zookeeper 加锁
     * 特殊说明：传递参数时请严格按照要求传递参数，如加锁一直失败，请先检查传递参数是否符合条件
     *
     * @param array $lock_args 加锁需要的参数列表，参数列表见下说明
     * 必需参数列表
     *   $lock_args['lock_key']  type:string 加锁的key，例如：order:pay:lock
     *   $lock_args['lock_value']  type:string 用于加锁的具体值，例如：order-2020
     * 
     * @return array 加锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $lock_data_arr['lock_ok']  type:bool 加锁成功与否，true:成功，false:失败
     *   $lock_data_arr['error_msg']  type:string 加锁失败时，错误消息
     *   $lock_data_arr['lock_ext_data']  type:array 加锁成功，返回的额外参数列表，具体数据结构如下所示：
     *      $lock_ext_data['get_lock']  type:bool 加锁是否成功
     *      $lock_ext_data['lock_node']  type:string 加锁成功的节点，只有 get_lock 为true时，该值才有真实意义，否则返回的结果只供参考
     * 
     */
    public function getLock(array $lock_args)
    {
        $lock_key = isset($lock_args['lock_key']) && $lock_args['lock_key'] ? $lock_args['lock_key'] : null;
        $lock_value = isset($lock_args['lock_value']) && $lock_args['lock_value'] ? $lock_args['lock_value'] : null;
        $lock_data_arr = array(
            'lock_ok' => false,
            'error_msg' => '',
            'lock_ext_data' => array()
        );
        if(!($lock_key && $lock_value))
        {
            return $lock_data_arr;
        }
        try
        {
            // 判断根节点是否存在
            if(!self::$_my_zk->exists(self::$root))
            {
                // 创建根节点
                $result = self::$_my_zk->create(self::$root, $lock_value, $this->_acl_arr);
                if(!$result)
                {
                    throw new Exception("10010:create root node " . self::$root . " fail");
                }
            }
			// 创建临时顺序节点            
            $sub_node = self::$root . "/" . $lock_key;
            // flags参数说明见 https://www.php.net/manual/zh/class.zookeeper.php
            // 0|null 永久节点，Zookeeper::EPHEMERAL 临时，Zookeeper::SEQUENCE 顺序，Zookeeper::EPHEMERAL|Zookeeper::SEQUENCE 临时顺序
            self::$_my_node = self::$_my_zk->create($sub_node, $lock_value, $this->_acl_arr, Zookeeper::EPHEMERAL|Zookeeper::SEQUENCE);
            if(false == self::$_my_node)
            {
                throw new Exception('10020:create sub-Zookeeper::EPHEMERAL|Zookeeper::SEQUENCE node ' . $sub_node . " fail");
            }
            // 获取锁
            $my_lock = $this->myLock();
            $lock_data_arr['lock_ok'] = $my_lock['get_lock'];
            $lock_data_arr['lock_ext_data'] = $my_lock;
            if(!$my_lock['get_lock'])
            {
                $lock_data_arr['error_msg'] = 'unkown error';
            }
        }
        catch (Exception $e)
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
     *   $lock_args['release_node']  type:string 需要解锁的节点 
     * 
     * @return array 解锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $release_lock_data_arr['release_lock_ok']  type:bool 解锁成功与否，true:成功，false:失败
     *   $release_lock_data_arr['error_msg']  type:string 解锁失败时，错误消息
     *   $release_lock_data_arr['release_lock_ext_data']  type:array 解锁成功，返回的额外参数列表，如无额外参数列表，返回空数组
     * 
     */
    public function releaseLock(array $lock_args)
    {
        $release_node = $lock_args['release_node'];
        $release_lock_data_arr = array(
            'release_lock_ok' => false,
            'error_msg' => '',
            'release_lock_ext_data' => ''
        );
        try
        {
            if(self::$_my_zk->delete($release_node)){
                $release_lock_data_arr['release_lock_ok'] = true;
            }
            else
            {
                $release_lock_data_arr['error_msg'] = '20000:release lock unkown error';;
            }
        }
        catch (Exception $e)
        {
            // 记录错误日志信息，方便后续进行问题排查
            $release_lock_data_arr['error_msg'] = '20010:' . $e->getMessage();
        }
        return $release_lock_data_arr;
    }
    
    /**
     * 真实的获取锁过程
     *
     * @return array 获取锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $my_lock_data['get_lock']  type:bool 获取锁成功与否，true:成功，false:失败
     *   $my_lock_data['lock_node']  type:string 获取锁成功的节点，供业务特殊使用
     * 
     */
    private function myLock()
    {
        $my_lock_data = array(
            'get_lock' => false,
            'lock_node' => ''
        );
		// 获取子节点列表从小到大，显然不可能为空，至少有一个节点
		$res = $this->myNodeLeastOrPrev();
        if($res['is_least'])
        {
            $my_lock_data['get_lock'] = true;
            $my_lock_data['lock_node'] = $res['least_node'];
            return $my_lock_data;
        }
        // 当前监听次数
        $watcher_num = 1;
        $get_lock = true;
        // 初始化状态值
        self::$_is_notifyed = false;
        try
        {
            // 考虑监听失败的情况：当我正要监听before之前，它被清除了，监听失败返回 false
            $result = self::$_my_zk->get($res['least_node'], [ZooKeeperLock::class, 'watcher']);
            while($result === false)
            {
                $res1 = $this->myNodeLeastOrPrev();
                if($res1['is_least'])
                {
                    $my_lock_data['get_lock'] = true;
                    $my_lock_data['lock_node'] = $res1['least_node'];
                    return $my_lock_data;
                }
                $result = self::$_my_zk->get($res1['least_node'], [ZooKeeperLock::class, 'watcher']);
            }
            // 阻塞，等待watcher被执行，watcher执行完回到这里
            while(!self::$_is_notifyed)
            {
                // 100ms 
                usleep(100000);
                $watcher_num++;
                if($watcher_num > self::$_max_wait_time)
                {
                    $get_lock = false;
                    break;
                }
            }
            $my_lock_data['get_lock'] = $get_lock;
        }
        catch (Exception $e)
        {
            // 记录错误日志信息，方便后续进行问题排查  $e->getMessage()
            $my_lock_data['get_lock'] = false;
        }
        $my_lock_data['lock_node'] = self::$_my_node;
        return $my_lock_data;
    }
    
    /**
     * 通知回调处理
     * @param $type 变化类型 Zookeeper::CREATED_EVENT, Zookeeper::DELETED_EVENT, Zookeeper::CHANGED_EVENT
     * @param $state
     * @param $key 监听的path
     */
    public static function watcher($type, $state, $key)
    {
        self::$_is_notifyed = true;
        $this->myLock();
    }
    
    /**
     * 检查自己节点是否为最小节点，也就意味着最小节点才能够获得锁
     *
     * @return array 获取锁成功与否以及额外信息，返回的 参数列表如下所示：
     *   $least_data['is_least']  type:bool 自己的节点是否为最小节点
     *   $least_data['least_node']  type:string 获取锁成功的节点，供业务特殊使用
     * 
     */
    private function myNodeLeastOrPrev()
    {
		$list = self::$_my_zk->getChildren(self::$root);
		sort($list);
		$root = self::$root;
		array_walk(
            $list, 
            function(&$val) use ($root)
            {
                $val = $root . '/' . $val;
            }
        );
        $rs = array(
            'is_least' => false,
            'least_node' => self::$_my_node
        );
        if($list[0] == self::$_my_node)
        {
            $rs['is_least'] = true;
            return $rs;
        }
        // 找到上一个节点
        $index = array_search(self::$_my_node, $list);
        if($index >= 1)
        {
            $before = $list[$index - 1];
            $rs['least_node'] = $before;
        }
        return $rs;
    }

}


function doSomething(){
	$n = rand(1, 20);
	switch($n){
		case 1: 
			sleep(15);// 模拟超时
			break;
		case 2:
			throw new \Exception('system throw message...');// 模拟程序中止
			break;
		case 3:
			die('system crashed...');// 模拟程序崩溃
			break;
		default:
			sleep(13);// 正常处理过程
	}
}

// 执行
zkLock(0);
