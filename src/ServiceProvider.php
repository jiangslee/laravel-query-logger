<?php

/*
 * This file is part of the overtrue/laravel-query-logger.
 *
 * (c) overtrue <i@overtrue.me>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\LaravelQueryLogger;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;

class ServiceProvider extends LaravelServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        if (!$this->app['config']->get('logging.query.enabled', false)) {
            return;
        }

        $trigger = $this->app['config']->get('logging.query.trigger');

        if (!empty($trigger) && !$this->requestHasTrigger($trigger)) {
            return;
        }

        $databases = config('logging.query.databases', ['mysql']);

        foreach ($databases as $db) {
            DB::connection($db)->enableQueryLog();
        }

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $request) use ($databases) {
            $queries = [];

            foreach ($databases as $db) {
                $queries = array_merge($queries, DB::connection($db)->getQueryLog());
            }

            if (!empty($queries)) {
                $queries = collect($queries)->map(function ($query) {
                    $res = [];
                    $sqlWithPlaceholders = str_replace(['%', '?', '%s%s'], ['%%', '%s', '?'], $query['query']);
                    $res['time'] = $this->formatDuration($query['time'] / 1000);
                    $res['sql'] = vsprintf($sqlWithPlaceholders, $query['bindings']);
                    return $res;
                });
            }

            Log::channel(config('logging.query.channel', config('logging.default')))
                ->debug(\sprintf('method: %s, uri: %s', request()->method(), request()->getRequestUri()), ['sqls' => $queries]);
        });
    }

    /**
     * Register the application services.
     */
    public function register()
    {
    }

    /**
     * @param string $trigger
     *
     * @return bool
     */
    public function requestHasTrigger($trigger)
    {
        return false !== getenv($trigger) || \request()->hasHeader($trigger) || \request()->has($trigger) || \request()->hasCookie($trigger);
    }

    /**
     * Format duration.
     *
     * @param  float  $seconds
     *
     * @return string
     */
    private function formatDuration($seconds)
    {
        if ($seconds < 0.001) {
            return round($seconds * 1000000).'μs';
        } elseif ($seconds < 1) {
            return round($seconds * 1000, 2).'ms';
        }

        return round($seconds, 2).'s';
    }
}
