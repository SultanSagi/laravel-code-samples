<?php

namespace SDC\AIDLogV3\Application\Query;

use API\Models\Person;
use API\Models\ProjectPerson;
use SDC\AIDLogV3\Model\AIDLog;
use SDC\AIDLogV3\Model\AIDLogType;
use Illuminate\Support\Collection;
use SDC\AIDLogV3\Model\AIDLogSection;
use Illuminate\Database\Eloquent\Builder;
use SDC\SharedKernel\Application\Dto\PersonDtoAssembler;
use SDC\SharedKernel\Foundation\Bus\MessageResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GetAvailablePeopleListForAssigneeHandler
{
    public function __invoke(GetAvailablePeopleListForAssigneeQuery $query, MessageResponse $messageResponse)
    {
        $sectionId = $query->sectionId();

        $sectionWithRestrictions = $this->getSectionRestrictions($sectionId);

        $aidLogId = $sectionWithRestrictions->getAIDLogId();
        $aidLog = AIDLog::find($aidLogId);
        $aidLogType = $aidLog->getAIDLogableType();
        $aidLogableId = $aidLog->getAIDLogableId();

        $aidLogOwnerId = $aidLog->getOwnerId();
        $aidLogOwner = Person::query()->find($aidLogOwnerId);

        if (AIDLogType::personal()->equalTo($aidLogType)) {
            $peopleList = $this->getAvailablePeopleBySectionPeopleRestrictions($aidLogId, $sectionWithRestrictions);
        } else {
            $peopleList = $this->getAvailablePeopleBySectionRolesRestrictions($aidLogId, $sectionWithRestrictions, $aidLogableId);
        }

        $peopleListDto = $peopleList->map(function ($person) {
            return PersonDtoAssembler::fromEloquentModel($person);
        });

        if (AIDLogType::personal()->equalTo($aidLogType)) {
            $peopleListDto->push(PersonDtoAssembler::fromEloquentModel($aidLogOwner));
        }

        $messageResponse->resolve($peopleListDto);
    }

    private function getAvailablePeopleBySectionRolesRestrictions($aidLogId, $sectionWithRestrictions, $projectId)
    {
        $aidLogFollowers = $this->getAidLogFollowersForProjectAidLog($aidLogId);

        $sectionRestrictions = collect();

        if ($sectionWithRestrictions->relationLoaded(AIDLogSection::RESTRICTED_ROLES_RELATION)) {
            /** @var Collection $sectionRestriction */
            $sectionRestrictions = $sectionWithRestrictions->getRelation(AIDLogSection::RESTRICTED_ROLES_RELATION)->pluck('id')->toArray();
        }

        $peopleList = $aidLogFollowers->filter(function ($follower) use ($sectionRestrictions, $projectId) {
            $followerProject = $follower['projects']->filter(function ($project) use ($projectId) {
                if ((int)$project['projectId'] !== (int)$projectId) {
                    return false;
                }
                return $project['roles'];
            })->first();

            if (null === $followerProject || null === $followerProject['roles']) {
                $followerRoles = [];
            } else {
                $followerRoles = $followerProject['roles']->pluck('roleId')->toArray();
            }

            if (count(array_diff($followerRoles, $sectionRestrictions)) === 0 && count($sectionRestrictions) !== 0) {
                return false;
            }

            return true;
        });

        return $peopleList;
    }

    private function getAidLogFollowersForProjectAidLog($aidLogId)
    {
        /** @var AIDLog $aidLog */
        $aidLog = AIDLog::query()
            ->where(AIDLog::ID_COLUMN, $aidLogId)
            ->whereIn(AIDLog::AIDLOGABLE_TYPE_COLUMN, [AIDLogType::project()->toString(), AIDLogType::collaborative()->toString()])
            ->with(sprintf("%s.%s.%s", AIDLog::FOLLOWERS_RELATION, Person::PERSON_PROJECT_RELATION,
                ProjectPerson::PROJECT_PERSON_ROLES_RELATION))
            ->first();

        return $aidLog->getRelation(AIDLog::FOLLOWERS_RELATION);
    }
}
