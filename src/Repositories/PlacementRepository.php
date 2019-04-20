<?php

namespace ClarkWinkelmann\CatchTheFish\Repositories;

use Carbon\Carbon;
use ClarkWinkelmann\CatchTheFish\Fish;
use ClarkWinkelmann\CatchTheFish\Ranking;
use ClarkWinkelmann\CatchTheFish\Validators\FishValidator;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\AssertPermissionTrait;
use Flarum\User\User;

class PlacementRepository
{
    use AssertPermissionTrait;

    /**
     * @param Fish $fish
     * @param array $placement
     * @throws ValidationException
     */
    protected function assertFishIsAtPlacement(Fish $fish, array $placement): void
    {
        foreach ([
                     'discussion_id',
                     'post_id',
                     'user_id',
                 ] as $key) {
            $value = $fish->{$key . '_placement'};
            if ($value && $value == array_get($placement, $key)) {
                return;
            }
        }

        /**
         * @var $translator Translator
         */
        $translator = app(Translator::class);

        throw new ValidationException([
            'placement' => $translator->trans('clarkwinkelmann-catch-the-fish.api.wrong-catch-placement'),
        ]);
    }

    /**
     * @param User $actor
     * @param Fish $fish
     * @param array $placement
     * @return Fish
     * @throws ValidationException
     * @throws \Flarum\User\Exception\PermissionDeniedException
     */
    public function catch(User $actor, Fish $fish, array $placement): Fish
    {
        $this->assertCan($actor, 'catch', $fish);

        $this->assertFishIsAtPlacement($fish, $placement);

        $fish->user_id_last_placement = null;
        $fish->last_caught_at = Carbon::now();
        $fish->lastUserCatch()->associate($actor);
        Placement::random()->assign($fish);

        $placementValidSince = Carbon::now();

        // Using permission directly instead of policy to not over-complicate time-based conditions
        // In the worst case we block the fish for a few minutes while nobody can edit it
        if ($actor->can('catchthefish.choose-place') || $actor->can('catchthefish.choose-name')) {
            /**
             * @var $settings SettingsRepositoryInterface
             */
            $settings = app(SettingsRepositoryInterface::class);
            $placementValidSince->addMinutes($settings->get('catch-the-fish.autoPlacedAfterMinutes', 5));
        }

        $fish->placement_valid_since = $placementValidSince;

        $fish->save();

        /**
         * @var $ranking Ranking
         */
        $ranking = $fish->round->userRanking($actor);

        if ($ranking) {
            $ranking->catch_count += 1;
            $ranking->save();
        } else {
            Ranking::create([
                'round_id' => $fish->round->id,
                'user_id' => $actor->id,
                'catch_count' => 1,
            ]);
        }

        return $fish;
    }

    /**
     * @param User $actor
     * @param Fish $fish
     * @param array $attributes
     * @return Fish
     * @throws ValidationException
     * @throws \Flarum\User\Exception\PermissionDeniedException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function place(User $actor, Fish $fish, array $attributes): Fish
    {
        // Need to clone fish otherwise policy checks in second block are impacted by the changes in first block
        $fishBeforeUpdate = clone $fish;

        if (array_has($attributes, 'placement')) {
            $this->assertCan($actor, 'place', $fishBeforeUpdate);

            if (array_get($attributes, 'placement') !== 'random') {
                $placement = new Placement();
                $placement->discussionId = array_get($attributes, 'placement.discussion_id');
                $placement->postId = array_get($attributes, 'placement.post_id');
                $placement->userId = array_get($attributes, 'placement.user_id');

                $placement->assertValid();

                $placement->assign($fish);
                $fish->lastUserPlacement()->associate($actor);
            }

            // If the request contains placement=random, we don't change anything but still immediately validate the random placement
            $fish->placement_valid_since = Carbon::now();
        }

        if (array_has($attributes, 'name')) {
            $this->assertCan($actor, 'name', $fishBeforeUpdate);

            /**
             * @var $validator FishValidator
             */
            $validator = app(FishValidator::class);
            $validator->assertValid($attributes);

            $fish->name = array_get($attributes, 'name');
            $fish->lastUserNaming()->associate($actor);

            // If the user can only rename fishes, after rename we immediately release the fish
            // If the user can place fishes, he's free to rename the fish as many times as he want before placing it
            if (!$actor->can('place', $fishBeforeUpdate)) {
                $fish->placement_valid_since = Carbon::now();
            }
        }

        if ($fish->isDirty()) {
            $fish->save();
        }

        return $fish;
    }
}
