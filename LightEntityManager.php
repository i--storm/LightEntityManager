<?php

namespace App\Bundle;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraints\DateTime;

class LightEntityManager{

    protected $em;
    protected $conn;
    protected $container;
    protected $sqls=array();

    public function __construct(EntityManager $oEntityManager, ContainerInterface $container)
    {
        $this->em = $oEntityManager;
        $this->container=$container;
        $this->conn=$oEntityManager->getConnection();
    }

    public function persist(&$entity){

        $reflection=new \ReflectionClass(get_class($entity));

        try {
            $reflection->getMethod("getId");
        }catch (\ReflectionException $e){// if there is no id field
            throw $e;
        }

        $className = $this->em->getClassMetadata(get_class($entity))->getName();

        //$fields = $this->em->getClassMetadata($className)->getFieldNames();

        $fields=$this->em->getClassMetadata($className)->fieldMappings;

        $associations=$this->em->getClassMetadata($className)->associationMappings;

        //$columns=$this->em->getClassMetadata($className)->getColumnNames();

        $is_entity_exists=$this->isEntityExists($entity);

        if($is_entity_exists){
            $existing_row=$this->getExistingRow($entity);
            if(!$existing_row){
                throw new \Exception("Existing row not found");
            }
        }

        $data=array();

        foreach($fields as $field=>$mapping){

            //$mapping=$this->em->getClassMetadata($className)->getFieldMapping($field);

            if($mapping['columnName']=='created_at' && !$is_entity_exists){
                $value=new \DateTime('now');//date("Y-m-d H:i:s",time());
            }elseif($mapping['columnName']=='updated_at' && $is_entity_exists){
                $value=new \DateTime('now');//date("Y-m-d H:i:s",time());
            }else {
                $value = $this->getFieldValue($entity, $field);
            }

            //TODO?? null тоже надо писать в базу на апдейтах
            if($value===null){
                continue;
            }

            if($is_entity_exists){
                if($this->compValues(
                    $existing_row[$mapping['columnName']],
                    $this->normValue(
                        $value,
                        $mapping['type'],
                        ""
                    ),
                    $mapping['type']
                )){
                    continue;
                }else{
                    $i=0;
                }
            }

            $data[$mapping['columnName']] = $this->normValue($value, $mapping['type']);

        }

        foreach($associations as $assoc=>$mapping){

            //$mapping=$this->em->getClassMetadata($className)->getAssociationMapping($assoc);

            if(!isset($mapping['joinColumns'])){
                continue;
            }

            $value=$this->getFieldValue($entity, $assoc);

            if(!$value){
                continue;
            }

            if(!method_exists($value,"getId")) {
                $className = $this->em->getClassMetadata(get_class($value))->getName();
                throw new \Exception("Method getId not exist in $className\n");
                //continue;
            }

            if($is_entity_exists){
                if($existing_row[$mapping['joinColumns'][0]['name']]==$value->getId()){
                    continue;
                }else{
                    $i=0;
                }
            }

            $data[$mapping['joinColumns'][0]['name']] = $value->getId();

        }

        if(!count($data)){
            return;
        }

        $tableName = $this->em->getClassMetadata($className)->getTableName();

        if($is_entity_exists){
            $this->sqls[]=array(
                "entity"=>$entity,
                "sql"=>$this->getUpdateSql($tableName, $data, $entity),
                "type"=>'update',
            );
        }else{
            $this->sqls[]=array(
                "entity"=>$entity,
                "sql"=>$this->getInsertSql($tableName, $data),
                "type"=>'insert',
            );
        }

    }

    public function remove(&$entity){

        $className = $this->em->getClassMetadata(get_class($entity))->getName();

        $tableName = $this->em->getClassMetadata($className)->getTableName();

        $sql="DELETE FROM $tableName WHERE id=".$entity->getId();

        $this->sqls[]=array(
            'entity'=>$entity,
            'sql'=>$sql,
            'type'=>'delete',
        );

    }

    public function refresh(&$entity){
        //stub only
    }

    public function flush(){

        foreach($this->sqls as $sql){

            //echo $sql['sql']."\n";

            $stmt=$this->conn->prepare($sql['sql']);

            try {
                $stmt->execute();
            }catch (\Exception $e){
                throw $e;
            }

            $id=$this->conn->lastInsertId();

            if($sql['type']=='insert') {
                $sql['entity']->setId($id);
            }

        }

        $this->sqls=array();

    }

    private function isEntityExists($entity){

        $id=$entity->getId();

        if($id==null){
            return false;
        }

        $className = $this->em->getClassMetadata(get_class($entity))->getName();

        $tableName = $this->em->getClassMetadata($className)->getTableName();

        $stmt=$this->conn->prepare("SELECT id FROM $tableName WHERE id=$id");

        $stmt->execute();

        $record=$stmt->fetch();

        if(is_array($record)){
            return true;
        }else{
            return false;
        }


    }

    private function getFieldValue($entity, $field){

        $separator="_";

        $words_arr=explode($separator, $field);

        foreach($words_arr as $key=>$word){
            $words_arr[$key]=ucfirst($word);
        }

        $method="get".implode("", $words_arr);

        if(method_exists($entity, $method)){
            $value = $entity->$method();
        }else{
            $value=null;
        }

        return $value;

    }

    private function getInsertSql($tableName, $data=array()){

        $columns="";
        $values="";

        foreach($data as $column=>$value){

            if(strlen($columns)>0){
                $columns.=",";
            }

            $columns.='`'.$column.'`';

            if(strlen($values)>0){
                $values.=",";
            }

            $values.=$value;

        }

        $sql="INSERT INTO $tableName ($columns) values ($values)";

        return $sql;

    }

    private function getUpdateSql($tableName, $data=array(), $entity){

        $sql_data="";

        foreach($data as $field=>$value){

            if(strlen($sql_data)>0){
                $sql_data.=",";
            }

            $sql_data.='`'.$field.'`='.$value;

        }

        $sql="UPDATE $tableName SET $sql_data WHERE id=".$entity->getId();

        return $sql;

    }

    public function normValue($value, $type, $dash="'"){

        if($type=="integer" || $type=="float" || $type=="bigint") {
            $value = $value;
        }elseif($type=="boolean"){
            $value = (int)$value;
        }elseif($type=="datetime") {
            if(is_object($value)) {
                $value = $dash . $value->format("Y-m-d H:i:s") . $dash;
            }else{
                $value = $value;
            }
        }elseif($type=="date") {
            if(is_object($value)) {
                $value = $dash . $value->format("Y-m-d") . $dash;
            }else{
                $value = $value;
            }
        }elseif($type=="array"){
            if($key=array_search("ROLE_USER",$value)){
                unset($value[$key]);
            }
            $value=$dash.serialize($value).$dash;
        }elseif($type=="string"){
            $value = $dash . $value . $dash;
        }else {
            throw new \Exception("Unknown type '$type' to norm");
        }

        return (string)$value;

    }

    public function getRepository($name){
        return $this->em->getRepository($name);
    }

    public function getExistingRow($entity){

        $className = $this->em->getClassMetadata(get_class($entity))->getName();

        $tableName = $this->em->getClassMetadata($className)->getTableName();

        $stmt=$this->conn->prepare("SELECT * FROM $tableName WHERE id=".$entity->getId());

        $stmt->execute();

        $row=$stmt->fetch();

        return $row;

    }

    public function compValues($value1,$value2,$type){

        if($type=="integer" || $type=="bigint") {
            $res = (int)$value1 == (int)$value2;
        }elseif($type=="float"){
            $res = (float)$value1 == (float)$value2;
        }elseif($type=="boolean"){
            $res = (boolean)$value1 == (boolean)$value2;
        }elseif($type=="datetime") {
            $res=strcmp($value1,$value2)===0;
        }elseif($type=="date") {
            $res=strcmp($value1,$value2)===0;
        }elseif($type=="array"){
            $res=strcmp($value1,$value2)===0;
        }elseif($type=="string"){
            $res=strcmp($value1,$value2)===0;
        }else {
            throw new \Exception("Unknown type '$type' to compare");
        }

        return $res;

    }

    public function getConnection(){
        return $this->conn;
    }

}
