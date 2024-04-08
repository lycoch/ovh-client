<?php

namespace OVHClient;


class OVH {
    var $lastMessage;
    var $services;
    var $project;
    var $client;
    var $ovhconf;

    /**
     * @return mixed
     */
    public function getOvhconf()
    {
        return $this->ovhconf;
    }

    public function __construct($apiConfig){
	$this->lastMessage='';
	foreach(['applicationKey','applicationSecret','endpoint','consumer_key'] as $field){
        	if(!isset($apiConfig[$field])){
	            die("FATAL: param $field is missing".PHP_EOL);
		}
	}
        $this->ovhconf=$apiConfig;
        $this->project=$this->ovhconf['mainproject'];

        $this->services['instance']=['name'=>'snapshotName'];
        $this->services['volume']=['name'=>'name'];
        $this->client=new \Ovh\Api($this->ovhconf['applicationKey'],$this->ovhconf['applicationSecret'],$this->ovhconf['endpoint'],$this->ovhconf['consumer_key']);
    }

    public function precreateVM($name,$flavor,$region,$os="Debian 9"){
        $vm['region']=$region;
	$vm['name']=$name;
	$vm['imageId']='';
        $vm['sshKeyId']=$this->apiGet("sshkey")[0]['id'];
        foreach($this->apiGet("flavor?region=".$vm['region']) as $f){
            if(strtolower($f['name'])==strtolower($flavor)){
                $vm['flavorId']=$f['id'];
            }
        }
        foreach($this->apiGet("image") as $f){
            if(strtolower($f['name'])==strtolower($os) && $f['region']==$vm['region']){
                $vm['imageId']=$f['id'];
            }
	}
	if(empty($vm['imageId'])){
		$vm['imageId']=$os;
	}
        return $vm;
    }
    protected function call($command){
        if($command[0]=='/'){
            return $command;
        }
        return sprintf('/cloud/project/%s/%s',$this->getProject(),$command);
    }

    public function getRecordID($zone,$subDomain,$fieldType='TXT'){
        $recordid = -1;
        $txtrecords = $this->apiGet("/domain/zone/$zone/record?fieldType=$fieldType");
        if (is_array($txtrecords)) {
            foreach ($txtrecords as $k => $res) {
                $record = $this->apiGet("/domain/zone/$zone/record/$res");
                if ($subDomain == $record['subDomain']) {
                    $recordid = $res;
                    break;
                }
            }
            return $recordid;
        }else{
			return 404;
		}
    }
    public function refreshZone($zone){
        $this->apiPost("/domain/zone/$zone/refresh");
        return $this->lastMessage();
    }
    public function createRecord($zone,$entry){
        $this->apiPost("/domain/zone/$zone/record", $entry);
        return $this->refreshZone($zone);
    }
    public function updateRecord($zone,$recordid,$entry){
        $this->apiPut("/domain/zone/$zone/record/$recordid", $entry);
        return $this->refreshZone($zone);
    }
    public function deleteRecord($zone,$recordid){
        $this->apiDelete("/domain/zone/$zone/record/$recordid");
        return $this->refreshZone($zone);
    }

    /**
     * @param mixed $project
     */
    public function setProject($project)
    {
        $this->project = $project;
    }

    /**
     * @return mixed
     */
    public function getProject()
    {
        return $this->project;
    }
    public function apiCallWithCheck($method,$call,$ckey,$cvalue){
        try{
            $ret=$this->client->$method($this->call($call));
            return $this->checkSuccess($ret,$ckey,$cvalue);
        }catch (\Exception $e){
            return $this->error($e->getMessage());
        }
    }
    public function apiDelete($call){
        try{
            $this->client->delete($this->call($call));
            return true;
        }catch (\Exception $e){
            return $this->error($e->getMessage());
        }
    }
    //post return NULL
    public function apiPost($call,$args=null){
        try{
            $this->client->post($this->call($call),$args);
            return true;
        }catch (\Exception $e){
            return $this->error($e->getMessage());
        }
    }
    public function apiGet($call){
        try{
            return $this->client->get($this->call($call));
        }catch (\Exception $e){
            return $this->error($e->getMessage());
        }
    }
    public function apiPut($call,$args){
        try{
            return $this->client->put($this->call($call),$args);
        }catch (\Exception $e){
            return $this->error($e->getMessage());
        }
    }

    public function listIP(){
        $all=array();
        foreach($this->client->get('/ip') as $ip){
            list($ip,$range)=explode('/',$ip);
            if(filter_var($ip, FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                $all[]=$ip;
            }
        }
        return $all;
    }

    public function getInstanceConf(){
        if(!isset($this->ovhconf['invent']))
            return [];
        return json_decode(file_get_contents($this->ovhconf['invent']),true);
    }

    public function strposa($haystack, $needle, $offset=0) {
        if(!is_array($needle)) $needle = array($needle);
        foreach($needle as $query) {
            if(strpos($haystack, $query, $offset) !== false) return true;
        }
        return false;
    }
    public function listVMWithFO($filter){
        $instances=$this->getInstanceConf();
        $all=array();
        foreach($this->apiGet('instance') as $ovhvm){
            if(count($filter)==0 || $this->strposa($ovhvm['name'],$filter)){
                $all[$ovhvm['id']]=$ovhvm;
            }
        }
        foreach($this->apiGet('ip/failover') as $fo) {
            if (isset($all[$fo['routedTo']])) {
		if(isset($instances[$fo['ip']])){
			$fo['instance'] =$instances[$fo['ip']];
		}else{
			$fo['instance']['instance'] ='FREE';
		}
                $all[$fo['routedTo']]['ipfo'][] = $fo;
            }
        }
        return $all;
    }
    public function listVM($filter){
        $instances=$this->getInstanceConf();
        $all=array();
        $ipfo=$this->apiGet('ip/failover');
        foreach($this->apiGet('instance') as $ovhvm){
            $name=$ovhvm['name'];
            if(count($filter)==0 || $this->strposa($name,$filter)){
                $all[$name]=$ovhvm;
                foreach($ipfo as $fo) {
                    if ($all[$name]['id']==$fo['routedTo']) {
                       	if(isset($instances[$fo['ip']])){
							$fo['instance'] =$instances[$fo['ip']];
						}else{
							$fo['instance']['instance'] ='FREE';
						}
                        $all[$name]['ipfo'][] = $fo;
                    }
                }
            }
        }
        return ['vm'=>$all];
    }
    public function reboot($instanceId,$type='soft'){
        $this->syslog("reboot called $instanceId -> $type");
        return $this->apiPost("instance/$instanceId/reboot",
            array('type'=>$type)
        );
    }
    public function changeFO($instanceId,$ipId){
        $this->syslog("changeFO called $instanceId -> $ipId");
        return $this->apiPost("ip/failover/$ipId/attach",
            array('instanceId'=>$instanceId)
        );
    }

    public function listFOForCurrentProject(){
        $all=array();
        foreach($this->apiGet('ip') as $list){
            if(filter_var($list['ip'], FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                $all[]=$list['ip'];
            }
        }
        return $all;
    }
    public function listProjects(){
        $all=array();
        foreach($this->apiGet('/cloud/project/') as $list){
            $all[$list]=$this->apiGet('/cloud/project/'.$list)["description"];
        }
        return $all;
    }

    public function getRegularIP(){
        $all=array();
        foreach ($this->listProjects() as $id=>$project){
            foreach($this->apiGet("/cloud/project/$id/instance") as $instances){
                foreach ($instances["ipAddresses"] as $ip){
                    if(filter_var($ip['ip'], FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                        $all[]=$ip['ip'];
                    }
                }
            }
        }
        return $all;
    }

    public function sortByDate($array){
        usort($array, function($a1, $a2) {
            if(!isset($a1['creationDate']))return 0;
            $v1 = strtotime($a1['creationDate']);
            $v2 = strtotime($a2['creationDate']);
            return $v2 - $v1;
        });
        return $array;
    }

    public function checkSuccess($ret,$key,$value){
        return is_array($ret) && isset($ret[$key]) && $ret[$key]==$value;
    }

    public function deleteSnapshot($snapshot){
        $this->syslog("deleteSnapshot called $snapshot");
        return $this->apiCallWithCheck('delete',"snapshot/$snapshot",'status','deleting');
    }
    public function deleteVolumeSnapshot($snapshot){
        return $this->apiDelete("volume/snapshot/$snapshot");
    }

    public function lastMessage(){
        return $this->lastMessage.PHP_EOL;
    }
    public function error($m){
        $this->lastMessage=$m;
        return false;
    }
    public function filterByName($service,$name,$sorted=false){
        $arr=$this->apiGet($service);
        if(!is_array($arr)){
            return false;
        }
        $filtered= array_filter($arr,function($value) use($name){
            return $name=='all' || strpos($value['name'],$name)!==false;
        });
        return $sorted ? $this->sortByDate($filtered): $filtered;
    }

    public function searchIpByInstanceName($name){
        return $this->getInfoByInstanceName($name)["ipAddresses"][0]['ip'];
    }
    public function getInfoByInstanceName($name){
        return current($this->filterByName('instance',$name));
    }
    public function getVolumeByName($name){
        return current($this->filterByName('volume',$name));
    }
    public function log($m){
        echo $m.PHP_EOL;
    }
    public function syslog($m){
        syslog(LOG_INFO,"[ovh.debug]$m");
    }

    public function createAndAttachVolume($name,$region,$size,$highspeed){
        $instanceId=@$this->getInfoByInstanceName($name)['id'];
        $this->log("vm[$instanceId] found with $name");
        $this->createVolume($this->getVolumeNameByInstance($name),$region,$size,$highspeed);
        sleep(15);
        $volumeId=@$this->getVolumeByName($this->getVolumeNameByInstance($name))['id'];
        $this->log("hd[$volumeId] found with $name");

        if(!empty($instanceId) && !empty($volumeId)){
            $this->log("attaching $volumeId to $instanceId");
            sleep(60);
            $this->attachVolume($volumeId,$instanceId);
            return true;
        }else{
            $this->log("instanceId or volume not found, cannot attach");
        }
        return false;
    }
    public function attachVolume($volumeId,$instanceId){
        return $this->apiPost("volume/$volumeId/attach",
            array('instanceId'=>$instanceId)
        );
    }
    public function createVolume($name,$region,$size,$highspeed){
        return $this->apiPost("volume",
            array('name'=>$name,'region'=>$region,'size'=> $size,'type'=>$highspeed?'high-speed':'classic'  )
        );
    }
    public function getVolumeNameByInstance($name){
        return "hd-$name";
    }
    public function needToDelete(&$ret,$keep){
        return count($ret)>$keep && ($ret=array_slice($ret,$keep));
    }

    public function getClient(){
        return $this->client;
    }
    /**
    *@deprec, use listIP() instead
    */
    public function listOvhIP() {
        return $this->listIP();
    }
    
    //@todo: sortir de la classe
    public function rotation($name,$keep){
        $ret=$this->filterByName('snapshot',$name,true);
        $m="";
        if($this->needToDelete($ret,$keep)){
            foreach($ret as $snap){
                if($this->deleteSnapshot($snap['id'])){
                    $m.="Snapshot ".$snap['name']." was successfully deleted".PHP_EOL;
                }else{
                    $m.="failed to delete ".$snap['name'].":".$this->lastMessage();
                }
            }
        }else{
            $m.="no rotation needed for $name".PHP_EOL;
        }
        $this->lastMessage=$m;
    }


    public function findByName($service,$name) {
        foreach($this->apiGet($service) as $res){
            if(strpos($res['name'],$name)!==false){
                return($res);
            }
        }
        return false;
    }

    public function checkIfSnapPending($name,&$snapshot){
        $allSnaps=$this->filterByName('snapshot',"$name-autosnap");
        $snapshot=reset($allSnaps);
        if(in_array(strtolower($snapshot['status']),['queued','saving'])){
            return !$this->error(sprintf("snapshot %s is pending, status %s",$snapshot['name'],$snapshot['status']));
        }
        return false;
    }

    public function checkService($service){
        return isset($this->services[$service]);
    }

    public function snap($service,$name){
        if(!$this->checkService($service)){
            return $this->error("service '$service' not available");
        }
        if($obj=$this->findByName($service,$name)){
            $id=$obj['id'];
            return $this->apiPost("$service/$id/snapshot",
                array($this->services[$service]['name'] => $obj['name']."-autosnap-".date("Y-m-d H:i:s")));
        }
        return $this->error("vm '$name' not found ");
    }
}
