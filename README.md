# locks
基于php对常见的分布式锁方案封装，包括基于 Redis、ZooKeeper、Etcd进行实现
关于锁基础知识，可以阅读美团技术文章的总结：https://tech.meituan.com/2018/11/15/java-lock.html


基于ETCD相应的操作api如下示例：



API接口响应通用参数列表

    etcd restful api接口正常响应 header 内容参数列表如下：
    --cluster_id  生成响应的集群的ID。
    --member_id  生成响应的成员的ID。
    --revision  生成响应时键值存储的 修订本，也就是版本号，该版本号全局唯一，操作时可指定相应的版本进行操作
    --raft_term  生成响应时成员的Raft术语。

    etcd restful api接口异常 响应 内容参数列表如下：
    --error  错误的具体内容
    --message 错误的消息明细
    --code  错误码

详细的请求参数列表见文档： https://etcd.io/docs/v3.4.0/learning/api/#requests-and-responses
关于基于 etcd 的锁机制实现方案可以参考： https://segmentfault.com/a/1190000021603215?utm_source=tag-newest


// 创建一个带过期时间的租约授权，参数必需为大写，并且租约ID自定义，如不自定义，租约ID通过响应结果返回的值进行获取
curl http://127.0.0.1:2379/v3/lease/grant -X POST -d '{"TTL": 60, "ID": 1591600570}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"28",
        "raft_term":"9"
    },
    "ID":"1234567",
    "TTL":"60"
}


// 加锁/释放锁请求的api参考： https://github.com/etcd-io/etcd/blob/master/etcdserver/api/v3lock/v3lockpb/v3lock.proto
// 加锁/释放锁参数列表参考： https://etcd.io/docs/v3.3.12/dev-guide/api_concurrency_reference_v3/

// 加锁
curl http://127.0.0.1:2379/v3/lock/lock -X POST -d '{"name": "YWJj", "lease": 1591600570}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"28",
        "raft_term":"9"
    },
    "key":"YWJjLzVlZGRlNWJh"
}

// 释放锁
curl http://127.0.0.1:2379/v3/lock/unlock -X POST -d '{"key": "YWJjLzVlZGRlNWJh"}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"29",
        "raft_term":"9"
    }
}

// 通过key-value方式写入一个带过期时间的租约key-value
curl http://127.0.0.1:2379/v3/kv/put -X POST -d '{"key": "YWJj", "value": "bHR4aW9uZzEyMzQ=", "lease": 1591600570}'
curl http://127.0.0.1:2379/v3/kv/put -X POST -d '{"key": "YWJj", "value": "a2tr", "lease": 1591600570}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"29",
        "raft_term":"9"
    }
}

// 通过kv/range 查询 相应key的结果
curl -L http://127.0.0.1:2379/v3/kv/range -X POST -d '{"key": "YWJj"}'
// 获取单条记录，并且只需要返回相应的keys而不返回值
curl -L http://127.0.0.1:2379/v3/kv/range -X POST -d '{"key": "YWJj", "limit": 1, "keys_only": true}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"29",
        "raft_term":"9"
    },
    "kvs":[
        {
            "key":"YWJj",
            "create_revision":"29",
            "mod_revision":"29",
            "version":"1",
            "value":"bHR4aW9uZzEyMzQ=",
            "lease":"1234567"
        }
    ],
    "count":"1"
}

// 取消租约，注意参数的大小写问题，以下2种方式完全一样
curl -L http://127.0.0.1:2379/v3/kv/lease/revoke -X POST -d '{"ID": "1591600570"}'
curl -L http://127.0.0.1:2379/v3/lease/revoke -X POST -d '{"ID": "1591600570"}'

如要取消的租约存在，响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"30",
        "raft_term":"9"
    }
}
如要取消的租约不存在，响应结果如下：
{
    "error":"etcdserver: requested lease not found",
    "message":"etcdserver: requested lease not found",
    "code":5
}

// 删除相应的key
curl -L http://127.0.0.1:2379/v3/kv/deleterange -X POST -d '{"key": "YWJj"}'
响应结果如下：
{
    "header":{
        "cluster_id":"5588143592968007030",
        "member_id":"1315068946984511305",
        "revision":"30",
        "raft_term":"9"
    },
    "deleted":"1"
}
