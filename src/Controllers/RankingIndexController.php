<?php

namespace ClarkWinkelmann\CatchTheFish\Controllers;

use ClarkWinkelmann\CatchTheFish\Repositories\RankingRepository;
use ClarkWinkelmann\CatchTheFish\Repositories\RoundRepository;
use ClarkWinkelmann\CatchTheFish\Serializers\RankingSerializer;
use Flarum\Api\Controller\AbstractListController;
use Flarum\User\AssertPermissionTrait;
use Flarum\User\Exception\PermissionDeniedException;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class RankingIndexController extends AbstractListController
{
    use AssertPermissionTrait;

    public $serializer = RankingSerializer::class;

    public $include = [
        'user',
    ];

    protected $rounds;
    protected $rankings;

    public function __construct(RoundRepository $rounds, RankingRepository $rankings)
    {
        $this->rounds = $rounds;
        $this->rankings = $rankings;
    }

    /**
     * @param ServerRequestInterface $request
     * @param Document $document
     * @return mixed
     * @throws PermissionDeniedException
     */
    protected function data(ServerRequestInterface $request, Document $document)
    {
        $roundId = array_get($request->getQueryParams(), 'id');

        $round = $this->rounds->findOrFail($roundId);

        $this->assertCan($request->getAttribute('actor'), 'listRankings', $round);

        return $this->rankings->all($round);
    }
}
