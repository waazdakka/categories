<?php
/*
 * This file is part of fof/categories
 *
 *  Copyright (c) FriendsOfFlarum.
 *
 *  For detailed copyright and license information, please view the
 *  LICENSE file that was distributed with this source code.
 */
namespace FoF\Categories;
use FoF\Categories\Content\Categories;
use Flarum\Api\Serializer\BasicUserSerializer;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Event\Hidden;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Restored;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Tags\Api\Controller\ListTagsController;
use Flarum\Tags\Api\Serializer\TagSerializer;
use Flarum\Frontend\Assets;
use Flarum\Settings\Event\Saved;
return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less')
        ->route('/categories', 'categories', Categories::class),
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),
    (new Extend\Settings())
        ->serializeToForum('categories.keepTagsNav', 'fof-categories.keep-tags-nav', 'boolval')
        ->serializeToForum('categories.fullPageDesktop', 'fof-categories.full-page-desktop', 'boolval')
        ->serializeToForum('categories.compactMobile', 'fof-categories.compact-mobile', 'boolval')
        ->serializeToForum('categories.parentRemoveIcon', 'fof-categories.parent-remove-icon', 'boolval')
        ->serializeToForum('categories.parentRemoveDescription', 'fof-categories.parent-remove-description', 'boolval')
        ->serializeToForum('categories.parentRemoveStats', 'fof-categories.parent-remove-stats', 'boolval')
        ->serializeToForum('categories.parentRemoveLastDiscussion', 'fof-categories.parent-remove-last-discussion', 'boolval')
        ->serializeToForum('categories.childBareIcon', 'fof-categories.child-bare-icon', 'boolval', true)
	->serializeToForum('categories.unreadEnabled', 'fof-categories.unread-enabled', function($value) { return (bool)(int)$value; })
	->serializeToForum('categories.unreadIconGlow', 'fof-categories.unread-icon-glow', function($value) { return (bool)(int)$value; })
	->serializeToForum('categories.unreadTitleColor', 'fof-categories.unread-title-color', function($value) { return (bool)(int)$value; })
	->serializeToForum('categories.unreadDot', 'fof-categories.unread-dot', function($value) { return (bool)(int)$value; })
	->serializeToForum('categories.unreadColor', 'fof-categories.unread-color', 'strval', '#e8a234')
	->serializeToForum('categories.unreadBadge', 'fof-categories.unread-badge', function($value) { return (bool)(int)$value; })
	->serializeToForum('categories.unreadBadgeText', 'fof-categories.unread-badge-text', 'strval', 'new')
	->serializeToForum('categories.unreadBadgeColor', 'fof-categories.unread-badge-color', 'strval', '#ff6000')
	->registerLessConfigVar('fof-categories-unread-color', 'fof-categories.unread-color', function($value) { return $value ?: '#e8a234'; })
	->registerLessConfigVar('fof-categories-unread-badge-color', 'fof-categories.unread-badge-color', function($value) { return $value ?: '#ff6000'; }),
    (new Extend\ApiController(ListTagsController::class))
        ->addOptionalInclude('lastPostedDiscussion.lastPostedUser'),
    (new Extend\ApiSerializer(TagSerializer::class))
        ->attributes(function ($serializer, $model, $attributes) {
            $settings = resolve(SettingsRepositoryInterface::class);
            if ($settings->get('fof-categories.small-forum-optimized', false)) {
                $result = $model->discussions()
                    ->selectRaw('sum(comment_count) as postCount, count(id) as discussionCount')
                    ->whereVisibleTo($serializer->getActor())
                    ->get()[0];
                $attributes['discussionCount'] = (int) $result['discussionCount'];
                $attributes['postCount'] = (int) $result['postCount'];
            } else {
                $attributes['postCount'] = (int) $model->post_count;
            }
            return $attributes;
        }),
    (new Extend\ApiSerializer(TagSerializer::class))
        ->attribute('hasUnread', function ($serializer, $model) {
            $actor = $serializer->getActor();
            if ($actor->isGuest()) {
                return false;
            }
            static $unreadTagIds = null;
            if ($unreadTagIds === null) {
                $unreadTagIds = Discussion::query()
                    ->join('discussion_tag', 'discussions.id', '=', 'discussion_tag.discussion_id')
                    ->leftJoin('discussion_user', function ($join) use ($actor) {
                        $join->on('discussion_user.discussion_id', '=', 'discussions.id')
                             ->where('discussion_user.user_id', '=', $actor->id);
                    })
                    ->whereVisibleTo($actor)
                    ->where('discussions.last_post_number', '>', 0)
                    ->whereNotNull('discussions.last_post_number')
                    ->where(function ($query) {
                        $query->whereNull('discussion_user.last_read_post_number')
                              ->orWhereColumn('discussion_user.last_read_post_number', '<', 'discussions.last_post_number');
                    })
                    ->pluck('discussion_tag.tag_id')
                    ->unique()
                    ->toArray();
            }
            return in_array($model->id, $unreadTagIds);
        }),
    (new Extend\ApiSerializer(BasicUserSerializer::class))
        ->attribute('joinTime', function ($serializer, $model) {
            return $serializer->formatDate($model->joined_at);
        }),
    new Extend\Locales(__DIR__.'/resources/locale'),
    (new Extend\Event())
        ->listen(Hidden::class, function (Hidden $event) {
            Util::updateTagsPostCount($event->post, -1);
        })
        ->listen(Posted::class, function (Posted $event) {
            Util::updateTagsPostCount($event->post, 1);
        })
        ->listen(Restored::class, function (Restored $event) {
            Util::updateTagsPostCount($event->post, 1);
        }),

(new Extend\Event())
    ->listen(Saved::class, function (Saved $event) {
        $unreadKeys = [
            'fof-categories.unread-enabled',
            'fof-categories.unread-icon-glow',
            'fof-categories.unread-title-color',
            'fof-categories.unread-dot',
            'fof-categories.unread-badge',
        ];
        foreach ($unreadKeys as $key) {
            if (isset($event->settings[$key])) {
                $assetsFactory = resolve('flarum.assets.factory');
                $assets = $assetsFactory('forum');
                $assets->makeCss()->flush();
                break;
            }
        }
    }),


];
