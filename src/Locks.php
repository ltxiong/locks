<?php
namespace Ltxiong\Locks;


/**
 * 分布式锁接口
 */
interface Locks
{

    /**
     * 加锁/获取锁
     *
     * @param array $lock_args 加锁需要的参数列表
     * @return array 加锁成功与否相应结果
     */
    public function getLock(array $lock_args);

    /**
     * 主动释放锁
     *
     * @param array $lock_args 释放锁需要的参数列表
     * @return array 锁释放成功与否相应结果
     */
    public function releaseLock(array $lock_args);

}
