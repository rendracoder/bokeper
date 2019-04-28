<?php

// 想先下哪个调整下顺序
// 不想下的可以删了
$class_array = array('無碼','中文字幕','有碼');

$total_counter = 0;
foreach($class_array as $class){
    $max_page = $list_num = 0;
    while (true) {
        $list_num ++;

        if($list_num > 1 && $list_num >= $max_page){
            echo "class:$class finish, go next!\n";
        }

        echo "getting list:[{$class}][page {$list_num}/$max_page]\n";
        $list_url = "https://api.netflav.com/video/getVideo?type=".urlencode($class)."&page={$list_num}&category=";
        $list_html = curl_get($list_url);

        // 匹配下最大页数
        if(empty($max_page)){
            preg_match("#\"pages\":(\d+)#", $list_html, $preg_max);
            if(empty($preg_max[1])){
                echo "max page not found!\n";
                exit();
            }else{
                $max_page = $preg_max[1];
                echo "max page: $max_page\n";
            }
        }


        // 匹配页面ID
        preg_match_all("#\"videoId\":\"(\w+)\"#", $list_html, $preg_page_ids);

        if(empty($preg_page_ids[1])){
            exit("no ids found in $list_url\n");
        }else{
            $page_ids = $preg_page_ids[1];
        }

        $count = count($page_ids);
        if($count > 0){
            echo "$count result found\n\n";
        }else{
            echo "no result found!\n\n";
            continue;
        }
        

        foreach($page_ids as $page_id){

            $total_counter ++;

            $page_url = "https://www.netflav.com/video?id={$page_id}";
            echo "(counter: $total_counter)getting page:[{$class}][page {$list_num} / $max_page] $page_url\n";


            // 检查 有没有下过
            $check = get_path($page_id);
            if(is_file($check)){
                echo "$page_id alreaded downloaded!($check)\n\n";
                continue;
            }else{
                $target_path = "$check/{$page_id}.mp4";
                $title_path = "$check/{$page_id}.title.txt";
            }



            $page_html = curl_get($page_url);


            /* 这里取到的番号不规范，放弃
            preg_match('#"code":"([\w\-]*?)","description"#', $page_html, $preg_id);
                var_dump($page_html);

            if(empty($preg_id[1])){
                echo "id not found!\n\n";
                continue;
            }else{
                $id = strtolower($preg_id[1]);
                echo "ID: $id\n";
            }
            */

            // 取标题
            preg_match("#<div class=\"videocoverheader_title\">(.*?)</div>#", $page_html, $preg_title);
            if(empty(trim($preg_title[1]))){
                echo "Title no found!\n\n";
                continue;
            }else{
                $title = trim($preg_title[1]);
            }

            preg_match("#https://www.avple.video/v/(\w+)#", $page_html, $preg_avple);
            if(empty($preg_avple[1])){
                echo "avple id not found!\n";
            }else{
                $avple_id = $preg_avple[1];
            }

            $avple_api = 'https://www.avple.video/api/source/' . $avple_id;
            $data = array('r'=>$page_url, 'd'=>'www.avple.video');
            $res = curl_post($avple_api, $data);
            $json_array = json_decode($res,TRUE);


            $file_fvs = $pix = '';
            foreach($json_array['data'] as $data){
                $cur_pix = str_ireplace('p', '', $data['label']);
                if($cur_pix > $pix){
                    $file_fvs = $data['file'];
                    $pix = $cur_pix;
                }
            }

            if(empty($file_fvs)){
                echo "file_fvs not found!\n\n";
                var_dump($json_array);exit();
                continue;
            }

            $res = curl_header($file_fvs);


            preg_match("#Location:(.*)#", $res, $preg_url);

            if(!empty(trim($preg_url[1]))){
                $url = trim($preg_url[1]);
            }else{
                echo "mp4 url not found!\n\n";
                continue;
            }

            echo "url found:$url\n";

            $ts = time();
            $time = date("[Y-m-d H:i:s]");
            echo "{$time} start to download ... \n";
            
            //  临时路径要和最终存放路径在一个盘上，否则改名的时候就要转移一次
            $tmp_path = "/$check/netflav.mp4";
            if(file_exists($tmp_path)){
                unlink($tmp_path);
            }
            $cmd = "axel -n 20 -q $url -o $tmp_path";
            system($cmd);

            $ext = get_ext($tmp_path);

            if($ext == 'mp4'){
                $file_size = floor(filesize($tmp_path) / 1024 / 1024);
                $ts_used = time() - $ts;
                $speed = floor(filesize($tmp_path) / 1024 / 1024 / $ts_used * 8);
                echo "Download finish!({$file_size} MB:{$target_path})\n";
                echo "$ts_used second used. speed: {$speed} Mbps\n\n";
                rename($tmp_path, $target_path);
                // 写入标题文件
                file_put_contents($title_path, $title);
            }else{
                echo "Download failed!\n\n";
                unlink($tmp_path);
            }

        }
    }
}


function get_ext($file_path){
    $finfo = finfo_open(FILEINFO_MIME_TYPE); // 返回 mime 类型
    $tmp = @explode('/', finfo_file($finfo, $file_path));
    if(!empty($tmp[1])){
        return $tmp[1];
    }else{
        return false;
    }
}



function get_path($id){
    // 检查片子是否存在
    $check = glob("/data/pan*/netflav/{$id}*");

    if(!empty($check)){
        return $check[0];
    }else{
        $pan_path_array = array('/data/pan1/', '/data/pan2/', '/data/pan3/', '/data/pan4/');
        foreach($pan_path_array as $pan_path){
            // 每个盘保留100G
            if(disk_free_space($pan_path) > 1024 * 1024 * 1024 * 100){
                if(!file_exists("{$pan_path}/netflav/")){
                    mkdir("{$pan_path}/netflav/", 0755, true);
                }
                return "{$pan_path}/netflav/";
            }
        }
    }

    exit("No disk available!\n");
}



function curl_post($url, $post_data){
    $ch = curl_init ();
    curl_setopt($ch, CURLOPT_POST , 1);
    curl_setopt($ch, CURLOPT_HEADER , 0);
    curl_setopt($ch, CURLOPT_URL , $url);
    curl_setopt($ch, CURLOPT_COOKIEJAR , '/tmp/spider.cookie');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible;Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)');
    curl_setopt($ch, CURLOPT_POSTFIELDS , $post_data);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT,600);
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.netflav.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FORWARDED-FOR:'.rand_ip())); 
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}


function curl_get($url){
    $ch = curl_init ();
    curl_setopt($ch, CURLOPT_HEADER , 0);
    curl_setopt($ch, CURLOPT_URL , $url);
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/spider.cookie');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible;Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT,600);
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.netflav.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FORWARDED-FOR:'.rand_ip())); 
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}


function curl_header($url){
    $ch = curl_init ();
    curl_setopt($ch, CURLOPT_HEADER , 1);
    curl_setopt($ch, CURLOPT_NOBODY , 1);
    curl_setopt($ch, CURLOPT_URL , $url);
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/spider.cookie');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible;Baiduspider-render/2.0; +http://www.baidu.com/search/spider.html)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT,600);
    curl_setopt($ch, CURLOPT_REFERER, 'https://www.netflav.com/');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FORWARDED-FOR:'.rand_ip())); 
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}



function rand_ip(){
    return rand(1,255).'.'.rand(1,255).'.'.rand(1,255).'.'.rand(1,255);
}