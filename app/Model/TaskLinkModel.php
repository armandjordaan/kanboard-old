<?php

namespace Kanboard\Model;

use Kanboard\Core\Base;
use Kanboard\Event\TaskLinkEvent;

/**
 * TaskLink model
 *
 * @package Kanboard\Model
 * @author  Olivier Maridat
 * @author  Frederic Guillot
 */
class TaskLinkModel extends Base
{
    /**
     * SQL table name
     *
     * @var string
     */
    const TABLE = 'task_has_links';

    /**
     * Events
     *
     * @var string
     */
    const EVENT_CREATE_UPDATE = 'tasklink.create_update';

    /**
     * Get a task link
     *
     * @access public
     * @param  integer   $task_link_id   Task link id
     * @return array
     */
    public function getById($task_link_id)
    {
        return $this->db->table(self::TABLE)->eq('id', $task_link_id)->findOne();
    }

    /**
     * Get the opposite task link (use the unique index task_has_links_unique)
     *
     * @access public
     * @param  array     $task_link
     * @return array
     */
    public function getOppositeTaskLink(array $task_link)
    {
        $opposite_link_id = $this->linkModel->getOppositeLinkId($task_link['link_id']);

        return $this->db->table(self::TABLE)
                    ->eq('opposite_task_id', $task_link['task_id'])
                    ->eq('task_id', $task_link['opposite_task_id'])
                    ->eq('link_id', $opposite_link_id)
                    ->findOne();
    }

    /**
     * Get all links attached to a task
     *
     * @access public
     * @param  integer   $task_id   Task id
     * @return array
     */
    public function getAll($task_id)
    {
        return $this->db
                    ->table(self::TABLE)
                    ->columns(
                        self::TABLE.'.id',
                        self::TABLE.'.opposite_task_id AS task_id',
                        LinkModel::TABLE.'.label',
                        TaskModel::TABLE.'.title',
                        TaskModel::TABLE.'.is_active',
                        TaskModel::TABLE.'.project_id',
                        TaskModel::TABLE.'.column_id',
                        TaskModel::TABLE.'.color_id',
                        TaskModel::TABLE.'.time_spent AS task_time_spent',
                        TaskModel::TABLE.'.time_estimated AS task_time_estimated',
                        TaskModel::TABLE.'.owner_id AS task_assignee_id',
                        UserModel::TABLE.'.username AS task_assignee_username',
                        UserModel::TABLE.'.name AS task_assignee_name',
                        ColumnModel::TABLE.'.title AS column_title',
                        ProjectModel::TABLE.'.name AS project_name'
                    )
                    ->eq(self::TABLE.'.task_id', $task_id)
                    ->join(LinkModel::TABLE, 'id', 'link_id')
                    ->join(TaskModel::TABLE, 'id', 'opposite_task_id')
                    ->join(ColumnModel::TABLE, 'id', 'column_id', TaskModel::TABLE)
                    ->join(UserModel::TABLE, 'id', 'owner_id', TaskModel::TABLE)
                    ->join(ProjectModel::TABLE, 'id', 'project_id', TaskModel::TABLE)
                    ->asc(LinkModel::TABLE.'.id')
                    ->desc(ColumnModel::TABLE.'.position')
                    ->desc(TaskModel::TABLE.'.is_active')
                    ->asc(TaskModel::TABLE.'.position')
                    ->asc(TaskModel::TABLE.'.id')
                    ->findAll();
    }

    /**
     * Get all links attached to a task grouped by label
     *
     * @access public
     * @param  integer   $task_id   Task id
     * @return array
     */
    public function getAllGroupedByLabel($task_id)
    {
        $links = $this->getAll($task_id);
        $result = array();

        foreach ($links as $link) {
            if (! isset($result[$link['label']])) {
                $result[$link['label']] = array();
            }

            $result[$link['label']][] = $link;
        }

        return $result;
    }

    /**
     * Publish events
     *
     * @access private
     * @param  array $events
     */
    private function fireEvents(array $events)
    {
        foreach ($events as $event) {
            $event['project_id'] = $this->taskFinderModel->getProjectId($event['task_id']);
            $this->container['dispatcher']->dispatch(self::EVENT_CREATE_UPDATE, new TaskLinkEvent($event));
        }
    }

    /**
     * Create a new link
     *
     * @access public
     * @param  integer   $task_id            Task id
     * @param  integer   $opposite_task_id   Opposite task id
     * @param  integer   $link_id            Link id
     * @return integer                       Task link id
     */
    public function create($task_id, $opposite_task_id, $link_id)
    {
        $events = array();
        $this->db->startTransaction();

        // Get opposite link
        $opposite_link_id = $this->linkModel->getOppositeLinkId($link_id);

        $values = array(
            'task_id' => $task_id,
            'opposite_task_id' => $opposite_task_id,
            'link_id' => $link_id,
        );

        // Create the original task link
        $this->db->table(self::TABLE)->insert($values);
        $task_link_id = $this->db->getLastId();
        $events[] = $values;

        // Create the opposite task link
        $values = array(
            'task_id' => $opposite_task_id,
            'opposite_task_id' => $task_id,
            'link_id' => $opposite_link_id,
        );

        $this->db->table(self::TABLE)->insert($values);
        $events[] = $values;

        $this->db->closeTransaction();

        $this->fireEvents($events);

        return (int) $task_link_id;
    }

    /**
     * Update a task link
     *
     * @access public
     * @param  integer   $task_link_id          Task link id
     * @param  integer   $task_id               Task id
     * @param  integer   $opposite_task_id      Opposite task id
     * @param  integer   $link_id               Link id
     * @return boolean
     */
    public function update($task_link_id, $task_id, $opposite_task_id, $link_id)
    {
        $events = array();
        $this->db->startTransaction();

        // Get original task link
        $task_link = $this->getById($task_link_id);

        // Find opposite task link
        $opposite_task_link = $this->getOppositeTaskLink($task_link);

        // Get opposite link
        $opposite_link_id = $this->linkModel->getOppositeLinkId($link_id);

        // Update the original task link
        $values = array(
            'task_id' => $task_id,
            'opposite_task_id' => $opposite_task_id,
            'link_id' => $link_id,
        );

        $rs1 = $this->db->table(self::TABLE)->eq('id', $task_link_id)->update($values);
        $events[] = $values;

        // Update the opposite link
        $values = array(
            'task_id' => $opposite_task_id,
            'opposite_task_id' => $task_id,
            'link_id' => $opposite_link_id,
        );

        $rs2 = $this->db->table(self::TABLE)->eq('id', $opposite_task_link['id'])->update($values);
        $events[] = $values;

        $this->db->closeTransaction();

        if ($rs1 && $rs2) {
            $this->fireEvents($events);
            return true;
        }

        return false;
    }

    /**
     * Remove a link between two tasks
     *
     * @access public
     * @param  integer   $task_link_id
     * @return boolean
     */
    public function remove($task_link_id)
    {
        $this->db->startTransaction();

        $link = $this->getById($task_link_id);
        $link_id = $this->linkModel->getOppositeLinkId($link['link_id']);

        $this->db->table(self::TABLE)->eq('id', $task_link_id)->remove();

        $this->db
            ->table(self::TABLE)
            ->eq('opposite_task_id', $link['task_id'])
            ->eq('task_id', $link['opposite_task_id'])
            ->eq('link_id', $link_id)->remove();

        $this->db->closeTransaction();

        return true;
    }
}