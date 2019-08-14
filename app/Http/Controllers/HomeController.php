<?php

/**
 *    Copyright (c) ppy Pty Ltd <contact@ppy.sh>.
 *
 *    This file is part of osu!web. osu!web is distributed with the hope of
 *    attracting more community contributions to the core ecosystem of osu!.
 *
 *    osu!web is free software: you can redistribute it and/or modify
 *    it under the terms of the Affero GNU General Public License version 3
 *    as published by the Free Software Foundation.
 *
 *    osu!web is distributed WITHOUT ANY WARRANTY; without even the implied
 *    warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *    See the GNU Affero General Public License for more details.
 *
 *    You should have received a copy of the GNU Affero General Public License
 *    along with osu!web.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace App\Http\Controllers;

use App;
use App\Libraries\CurrentStats;
use App\Libraries\Search\AllSearch;
use App\Models\BeatmapDownload;
use App\Models\Beatmapset;
use App\Models\Forum\Post;
use App\Models\NewsPost;
use App\Models\User;
use App\Models\UserDonation;
use Auth;
use Request;
use View;

class HomeController extends Controller
{
    protected $section = 'home';

    public function __construct()
    {
        $this->middleware('auth', [
            'only' => [
                'downloadQuotaCheck',
                'search',
            ],
        ]);

        return parent::__construct();
    }

    public function bbcodePreview()
    {
        $post = new Post(['post_text' => Request::input('text')]);

        return $post->bodyHTML();
    }

    public function downloadQuotaCheck()
    {
        return [
            'quota_used' => BeatmapDownload::where('user_id', Auth::user()->user_id)->count(),
        ];
    }

    public function getDownload()
    {
        return view('home.download');
    }

    public function index()
    {
        $host = Request::getHttpHost();
        $subdomain = substr($host, 0, strpos($host, '.'));

        if ($subdomain === 'store') {
            return ujs_redirect(route('store.products.index'));
        }

        if (Auth::check()) {
            $news = NewsPost::default()->limit(NewsPost::DASHBOARD_LIMIT + 1)->get();
            $newBeatmapsets = Beatmapset::latestRankedOrApproved();
            $popularBeatmapsetsPlaycount = Beatmapset::mostPlayedToday();
            $popularBeatmapsetIds = array_keys($popularBeatmapsetsPlaycount);
            $popularBeatmapsets = Beatmapset::whereIn('beatmapset_id', $popularBeatmapsetIds)
                ->orderByField('beatmapset_id', $popularBeatmapsetIds)
                ->get();

            return view('home.user', compact(
                'newBeatmapsets',
                'news',
                'popularBeatmapsets',
                'popularBeatmapsetsPlaycount'
            ));
        } else {
            return view('home.landing', ['stats' => new CurrentStats()]);
        }
    }

    public function messageUser($user)
    {
        // TODO: REMOVE ONCE COMPLETELY LIVE
        $canWebChat = false;
        if (Auth::check()) {
            if (Auth::user()->isPrivileged()) {
                $canWebChat = true;
            }
            if (config('osu.chat.webchat_enabled_supporter') && Auth::user()->isSupporter()) {
                $canWebChat = true;
            }
            if (config('osu.chat.webchat_enabled_all')) {
                $canWebChat = true;
            }
        }

        if (!$canWebChat) {
            return ujs_redirect("https://osu.ppy.sh/forum/ucp.php?i=pm&mode=compose&u={$user}");
        } else {
            return ujs_redirect(route('chat.index', ['sendto' => $user]));
        }
    }

    public function osuSupportPopup()
    {
        return view('objects._popup_support_osu');
    }

    public function search()
    {
        if (request('mode') === 'beatmapset') {
            return ujs_redirect(route('beatmapsets.index', ['q' => request('query')]));
        }

        $allSearch = new AllSearch(request(), ['user' => Auth::user()]);
        $isSearchPage = true;

        return view('home.search', compact('allSearch', 'isSearchPage'));
    }

    public function setLocale()
    {
        $newLocale = get_valid_locale(Request::input('locale')) ?? config('app.fallback_locale');
        App::setLocale($newLocale);

        if (Auth::check()) {
            Auth::user()->update([
                'user_lang' => $newLocale,
            ]);
        }

        return js_view('layout.ujs-reload')
            ->withCookie(cookie()->forever('locale', $newLocale));
    }

    public function supportTheGame()
    {
        if (Auth::check()) {
            $user = Auth::user();

            // current status
            $expiration = optional($user->osu_subscriptionexpiry)->addDays(1);
            $current = $expiration !== null ? $expiration->isFuture() : false;

            // purchased
            $tagPurchases = $user->supporterTagPurchases;
            $dollars = $tagPurchases->sum('amount');
            $cancelledTags = $tagPurchases->where('cancel', true)->count() * 2; // 1 for purchase transaction and 1 for cancel transaction
            $tags = $tagPurchases->count() - $cancelledTags;

            // gifted
            $gifted = $tagPurchases->where('target_user_id', '<>', $user->user_id);
            $giftedDollars = $gifted->sum('amount');
            $canceledGifts = $gifted->where('cancel', true)->count() * 2; // 1 for purchase transaction and 1 for cancel transaction
            $giftedTags = $gifted->count() - $canceledGifts;

            $supporterStatus = [
                // current status
                'current' => $current,
                'expiration' => $expiration,
                // purchased
                'dollars' => currency($dollars, 2, false),
                'tags' => i18n_number_format($tags),
                // gifted
                'giftedDollars' => currency($giftedDollars, 2, false),
                'giftedTags' => i18n_number_format($giftedTags),
            ];

            if ($current) {
                $lastTagPurchaseDate = UserDonation::where('target_user_id', $user->user_id)
                    ->orderBy('timestamp', 'desc')
                    ->pluck('timestamp')
                    ->first();

                if ($lastTagPurchaseDate === null) {
                    $lastTagPurchaseDate = $expiration->copy()->subMonths(1);
                }

                $total = $expiration->diffInDays($lastTagPurchaseDate);
                $used = $lastTagPurchaseDate->diffInDays();

                $supporterStatus['remainingRatio'] = 100 - round(($used / $total) * 100, 2);
            }
        }

        return view('home.support-the-game')
            ->with('supporterStatus', $supporterStatus ?? [])
            ->with('data', [
                // why support sections
                'blocks' => [
                    // localization's name => array of icons
                    'team' => ['fas fa-users'],
                    'infra' => ['fas fa-server'],
                    'featured-artists' => ['fas fa-user-astronaut'],
                    'ads' => ['fas fa-ad', 'fas fa-slash'],
                    'tournaments' => ['fas fa-trophy'],
                    'bounty-program' => ['fas fa-child'],
                ],

                // supporter perks
                'perks' => [
                    // localization's name => icon
                    [
                        'type' => 'image',
                        'name' => 'osu_direct',
                        'icon' => 'fas fa-search',
                        'image' => '/images/layout/supporter/direct.png',
                    ],
                    [
                        'type' => 'group',
                        'items' => [
                            'auto_downloads' => 'fas fa-download',
                            'upload_more' => 'fas fa-cloud-upload-alt',
                            'early_access' => 'fas fa-flask',
                        ],
                    ],
                    [
                        'type' => 'image-flipped',
                        'name' => 'beatmap_filters',
                        'icon' => 'fas fa-filter',
                        'image' => '/images/layout/supporter/filter.jpg',
                    ],
                    [
                        'type' => 'hero',
                        'name' => 'customisation',
                        'icon' => 'fas fa-image',
                        'image' => '/images/layout/supporter/customisation.jpg',
                    ],
                    [
                        'type' => 'image-group',
                        'items' => [
                            'yellow_fellow' => [
                                'icon' => 'fas fa-fire',
                                'image' => '/images/layout/supporter/yellow_fellow.jpg',
                            ],
                            'speedy_downloads' => [
                                'icon' => 'fas fa-tachometer-alt',
                                'image' => '/images/layout/supporter/speedy_downloads.jpg',
                            ],
                            'change_username' => [
                                'icon' => 'fas fa-magic',
                                'image' => '/images/layout/supporter/change_username.jpg',
                            ],
                            'skinnables' => [
                                'icon' => 'fas fa-paint-brush',
                                'image' => '/images/layout/supporter/skinnables.jpg',
                            ],
                        ],
                    ],
                ],
            ]);
    }
}
