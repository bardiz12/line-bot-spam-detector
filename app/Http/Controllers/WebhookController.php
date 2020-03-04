<?php

namespace App\Http\Controllers;

use LINE\LINEBot;
use App\Model\User;
use App\Model\Group;
use App\Model\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use LINE\LINEBot\TemplateActionBuilder\UriTemplateActionBuilder;
use LINE\LINEBot\TemplateActionBuilder\MessageTemplateActionBuilder;
use LINE\LINEBot\MessageBuilder\TemplateBuilder\ButtonTemplateBuilder;


class WebHookController extends Controller{
    
    private $basicFunction = ["help","about","aktifkan","nonaktifkan","infokategori"];

    private $users = [];
    private $groups = [];

    public function __construct(){
        $httpClient = new CurlHTTPClient(getenv('CHANNEL_ACCESS_TOKEN'));
        $this->bot  = new LINEBot($httpClient, ['channelSecret' => getenv('CHANNEL_SECRET')]);
    }
    
    public function index(Request $request, Response $response){
        $this->handleEvents($request);
    }


    private function handleEvents($request){
        $events = $request->input('events');
        if(!$events){
            return;
        }
        
        foreach ($events as $key => $event) {
            $event = (Object) $event;
            $this->userRegistrationMiddleware($event);
            if(method_exists($this, "handle" . ucwords($event->type))) $this->{"handle" . ucwords($event->type)}($event);
        }
        
        Log::info(\json_encode($events, JSON_PRETTY_PRINT));        
    }

    private function handleFollow($event){
        
        $messageBuilder = new TextMessageBuilder("Hai Selamat datang di Polisi Spam Online!. ketik help untuk bantuan");
    
        $response = $this->bot->replyMessage($event->replyToken, $messageBuilder);
    }

    private function handleJoin($event){
        $messageBuilder = new TextMessageBuilder("Terimakasih telah menambahkan *Polisi SPAM Online* di grup ini. ketik help untuk bantuan");
        $response = $this->bot->replyMessage($event->replyToken, $messageBuilder);
    }

    private function handleMessage($event){
        Log::info(\json_encode($event, JSON_PRETTY_PRINT) . "FROM handleMessage");    
        $event->message = (Object) $event->message;

        if($event->message->type !== "text"){
            return;
        }

        $text = $event->message->text;
        $calon_fungsi = strtolower(str_replace(" ","",trim($text)));
        if(in_array($calon_fungsi, $this->basicFunction)){
            $this->basicFunctionHandling($calon_fungsi, $event);
            return;
        }else{
            $this->handleBasicMessage($event);
        }
        
    }

    private function callML($text){
        $postdata = http_build_query(
            array(
                'msg' => $text,
                'key' => '41ebbba727746aaa399a15a748af0878'
            )
        );
        
        $opts = array('http' =>
            array(
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $postdata
            )
        );
        
        $context  = stream_context_create($opts);
        
        $result = @file_get_contents('https://bard-ai1.herokuapp.com/predict', false, $context);

        if($result){
            $result = json_decode($result);
            return $result;
        }
        return null;
    }

    private function generateResultClasifier($result){
        $msg = "";
        if($result->kategori == "Pesan Normal"){
            $tipe = "🙂 PESAN NORMAL";
            $image = "https://1.bp.blogspot.com/-3xYvDce6xrk/U5RlK-SNeYI/AAAAAAAAEWg/-Vher3UbBY4/s640/jempol.jpg";
            $msg.= "Pesan diatas termasuk kedalam pesan Normal";
        }else if($result->kategori == "Pesan Penipuan"){
            $msg.= "😈";
            $tipe = "😈 PESAN PENIPUAN";
            $msg.= "AWAS!!. Pesan diatas termasuk kedalam pesan Penipuan.";
            $image = "https://encrypted-tbn0.gstatic.com/images?q=tbn%3AANd9GcR6yfzUT5mqscljBSHE3LKjSvcZsZnYPtqFLXojhAJiMlm2rl8E";
        }else {
            $tipe = "📣 PESAN PROMOSI";
            $msg.= "Pesan diatas termasuk kedalam pesan Iklan/promosi.";
            $image = "https://qazwa.id/blog/wp-content/uploads/2019/09/cara-promosi-produk.jpg";
        }

        $options = [
            new MessageTemplateActionBuilder("INFO", "info kategori"),
        ];
        
        $buttonTemplate = new ButtonTemplateBuilder($tipe, $msg, $image, $options);
        
        $messageBuilder = new TemplateMessageBuilder($tipe . "\n" . $msg, $buttonTemplate);
        Log::info(json_encode($messageBuilder->buildMessage()));
        return $messageBuilder;
    }

    private function handleBasicMessage($event){
        $source = (Object) $event->source;
        if($source->type === "user"){
            $user = $this->getUserByLineId($source->userId);
            if($user->active){
                if(strlen($event->message->text) < 30){
                    
                    return new TextMessageBuilder("Maaf pesan yang dapat dideteksi harus memiliki panjang karakter minimal 30");
                    
                }

                $klasifikasi = $this->callML($event->message->text);
                $message = $this->generateResultClasifier($klasifikasi);
                $response = $this->bot->replyMessage($event->replyToken, $message);
                error_log(print_r($response, true));
                Log::info("resp " . json_encode($response, JSON_PRETTY_PRINT));
            }else{
                $message = new TextMessageBuilder("Kamu dalam status nonaktif. bot tidak akan memeriksa pesan yang kamu kirimkan apabila dalam status nonaktif");
                $this->bot->replyMessage($event->replyToken, $message);
            }
            
        }else{
            $group = $this->getGroupByGroupLineId($source->groupId);
            if($group->active){
                if(strlen($event->message->text) >= 30){
                    $klasifikasi = $this->callML($event->message->text);
                    if($klasifikasi->kategori != "Pesan Normal"){
                        $message = $this->generateResultClasifier($klasifikasi);
                        $response = $this->bot->replyMessage($event->replyToken, $message);
                        error_log(print_r($response, true));
                        Log::info("resp " . json_encode($response, JSON_PRETTY_PRINT));
                    }                    
                }
            }
        }
    }

    private function basicFunctionHandling($fungsi, $event){
        $registered = true;
        
        $type = $event->source['type'];
        if($type === 'user'){
            $user = $this->getUserByLineId($event->source['userId']);
        }else{
            $group = $this->getGroupByGroupLineId($event->source['groupId']);
        }

        if($fungsi == "help"){
            $view = view('help');
            Log::info($view);
            $message = new TextMessageBuilder($view->render());
        } else if($fungsi == "infokategori"){
            $view = view('infokategori');
            $message = new TextMessageBuilder($view->render());
        } else if($fungsi == "about"){
            $buttonTemplate = new ButtonTemplateBuilder("Tentang", "Bot ini dibuat oleh Bardizba Z. Profile: ", "https://pbs.twimg.com/profile_images/1233169650109775881/XGyNC3VC_400x400.jpg", [
                new UriTemplateActionBuilder("🌐 Website", "https://bardiz.digital"),
                new UriTemplateActionBuilder("🔗 Github", "https://github.com/bardiz12")
            ]);
            if($type == 'user'){
                $user->active = false;
                $user->save();
            }else{
                $group->active = false;
                $group->save();
            }
            $message = new TemplateMessageBuilder("Bot ini dibuat oleh Bardizba Z", $buttonTemplate);
        } else if($fungsi == "aktifkan"){
            $buttonTemplate = new ButtonTemplateBuilder("Berhasil!!", "BOT akan ngasih tau kamu kalo ada SMS SPAM/Penipuan!", "https://www.comodo.com/images/best-spam-fighter.png", [
                new MessageTemplateActionBuilder("INFO", "info kategori"), 
                new MessageTemplateActionBuilder("Matikan Bot", "nonaktifkan"), 
            ]);
            if($type == 'user'){
                $user->active = true;
                $user->save();
            }else{
                $group->active = true;
                $group->save();
            }

            $message = new TemplateMessageBuilder("BOT akan ngasih tau kamu kalo ada SMS SPAM/Penipuan!", $buttonTemplate);
        } else if($fungsi == "nonaktifkan"){
            $buttonTemplate = new ButtonTemplateBuilder("Berhasil!!", "BOT berhasil dimatikan!.", "https://cdn2.iconfinder.com/data/icons/robot-character-emoji-sticker-big-set/100/Robot_sticer_color_set-18-512.png", [
                new MessageTemplateActionBuilder("INFO", "info kategori"), 
                new MessageTemplateActionBuilder("Aktifkan Bot", "aktifkan"), 
            ]);
            if($type == 'user'){
                $user->active = false;
                $user->save();
            }else{
                $group->active = false;
                $group->save();
            }
            $message = new TemplateMessageBuilder("BOT berhasil dimatikan!", $buttonTemplate);
        }else{
            $view = view('noaction');
            $message = new TextMessageBuilder($view->render());
        }

       
        return $this->bot->replyMessage($event->replyToken, $message);
    }

    private function userRegistrationMiddleware($event){
        $source = (Object) $event->source;

        $type = $source->type;
        if($type == 'user'){
            $user = User::where('line_user_id', $source->userId)->first();
            if($user == null){
                $user = User::create([
                    'line_user_id' => $source->userId,
                    'is_following' => true,
                    'active'=>true
                ]);
                Log::info(json_encode($user->toArray()));
            }
            $this->users[$source->userId] = $user;
        }else if($type == 'group'){
            $group = Group::where("group_id",$source->groupId)->first();
            if($group == null){
                $group = Group::create([
                    'group_id'=>$source->groupId,
                    'line_user_id' => $source->userId,
                    'is_joining' => true,
                    'active'=>true
                ]);
                Log::info(json_encode($group->toArray()));
            }

            $this->groups[$group->group_id] = $group;
        }
    }

    private function getUserByLineId($line_id){
        if(!in_array($line_id, $this->users)){

        }

        return $this->users[$line_id];
    }

    private function getGroupByGroupLineId($group_line_id){
        if(!in_array($group_line_id, $this->groups)){

        }

        return $this->groups[$group_line_id];
    }

    
}