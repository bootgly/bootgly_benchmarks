<?php

namespace App\Http\Controllers;


use const ENT_QUOTES;
use function asort;
use function htmlspecialchars;
use function max;
use function min;
use function random_int;

use App\Models\Fortune;
use App\Models\World;
use Illuminate\Http\Request;


// TechEmpower benchmark routes — mirrors bootables/swoole/swoole-techempower-postgres.php

class BenchmarkController extends Controller
{
    public function plaintext()
    {
        return response('Hello, World!', 200, ['Content-Type' => 'text/plain']);
    }

    public function json()
    {
        return response()->json(['message' => 'Hello, World!']);
    }

    public function db()
    {
        $world = World::find(random_int(1, 10000));

        return response()->json(['id' => (int) $world->id, 'randomNumber' => (int) $world->randomnumber]);
    }

    public function query(Request $request)
    {
        $queries = max(1, min(500, (int) $request->query('queries', 1)));

        $worlds = [];
        for ($i = 0; $i < $queries; $i++) {
            $world = World::find(random_int(1, 10000));
            $worlds[] = ['id' => (int) $world->id, 'randomNumber' => (int) $world->randomnumber];
        }

        return response()->json($worlds);
    }

    public function fortunes()
    {
        $fortunes = [];
        foreach (Fortune::all(['id', 'message']) as $fortune) {
            $fortunes[(int) $fortune->id] = (string) $fortune->message;
        }
        $fortunes[0] = 'Additional fortune added at request time.';
        asort($fortunes);

        $rows = '';
        foreach ($fortunes as $id => $message) {
            $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            $rows .= "<tr><td>{$id}</td><td>{$message}</td></tr>";
        }

        $html = '<!DOCTYPE html><html><head><title>Fortunes</title></head><body>'
            . '<table><tr><th>id</th><th>message</th></tr>' . $rows . '</table></body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function updates(Request $request)
    {
        $queries = max(1, min(500, (int) $request->query('queries', 1)));

        $worlds = [];
        for ($i = 0; $i < $queries; $i++) {
            $world = World::find(random_int(1, 10000));
            $world->randomnumber = random_int(1, 10000);
            $world->save();
            $worlds[] = ['id' => (int) $world->id, 'randomNumber' => (int) $world->randomnumber];
        }

        return response()->json($worlds);
    }
}
