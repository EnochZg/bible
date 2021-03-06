<?php

namespace common\models;

use yii\db\ActiveRecord;

class Intercession extends ActiveRecord
{
    public function table()
    {
        return 'public.intercession';
    }

    public function rules()
    {
        return [
            [['privacy', 'user_id', 'content', 'ip', 'comments', 'intercessions', 'created_at'], 'required'],
            [['updated_at', 'position'], 'safe']
        ];
    }

    public function add($data)
    {
        $this->isNewRecord = true;
        $this->attributes = $data;
        if($this->save()) {
            return $this->primaryKey();
        }
        return false;
    }

    public static function findAllByUserId($userId, $startPage = 1, $pageNo = 10)
    {
        return self::find()->where(['user_id' => $userId])->limit($pageNo)->offset(($startPage - 1) * $pageNo)->orderBy('id desc')->all();
    }

    /**
     * 修改数据
     * @param $data
     * @param $userId
     * @return int
     */
    public static function mod($data, $userId)
    {
        return self::updateAll($data, 'user_id = :user_id', ['user_id' => $userId]);
    }

    public static function findAllByFriendsId($friendsId, $startPage, $pageNo)
    {
        $sql = "
            select b.id, b.content, b.user_id, a.nickname, b.created_at, b.position, b.intercessions, a.gender from public.user a
            inner join public.intercession b on a.id = b.user_id
            where a.id in (".$friendsId.") order by b.id desc limit %d offset %d
        ";
        $sql = sprintf($sql, $pageNo, ($startPage - 1) * $pageNo);
        return self::getDb()->createCommand($sql)->queryAll();
    }

    /**
     * @param $id
     * @return null|static
     */
    public static function findByIntercessionId($id)
    {
        return self::findOne(['id' => $id]);
    }

    /**
     * 递增代祷表`加入代祷`数量
     * @param $intercessionId
     * @return bool
     * @throws Exception
     */
    public function increaseIntercessions($intercessionId)
    {
        $sql = "update public.intercession set intercessions = intercessions + 1 where id = %d";
        $sql = sprintf($sql, $intercessionId);
        $is = $this->getDb()->createCommand($sql)->execute();
        if(!$is)
            throw new Exception(json_encode($this->getErrors()));
        return true;
    }

    /**
     * 递增代祷表`评论`数量
     * @param $intercessionId
     * @return bool
     * @throws Exception
     */
    public function increaseComments($intercessionId)
    {
        $sql = "update public.intercession set comments = comments + 1 where id = %d";
        $sql = sprintf($sql, $intercessionId);
        $is = $this->getDb()->createCommand($sql)->execute();
        if(!$is)
            throw new Exception(json_encode($this->getErrors()));
        return true;
    }
}