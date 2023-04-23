<?php
/** Plugin Task CG Indexer : indexation des contenus à indexer dans la recherche avancée
 * Version			: 1.0.3
 * copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
 * license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 *
 *
 */
namespace ConseilGouz\Plugin\Task\CGIndexer\Extension;

defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Language\Text;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\DispatcherInterface;

Use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Model\IndexModel;

final class CGIndexer extends CMSPlugin implements SubscriberInterface {
    use TaskPluginTrait;
    /**
     * @var boolean
     * @since 4.1.0
     */
    protected $autoloadLanguage = true;
    /**
     * @var string[]
     *
     * @since 4.1.0
     */
    protected const TASKS_MAP = [
        'goftp' => [
            'langConstPrefix' => 'PLG_TASK_CGINDEXER',
            'form'            => 'cgindexer',
            'method'          => 'cgindexer',
        ],
    ];
	protected $myparams;
    /**
     * Start time for the index process
     * @var    string
     */
    private $time;
    
    /**
     * Start time for each batch
     * @var    string
     */
    private $qtime;
    
    /**
     * Static filters information.
     * @var    array
     */
    
    private $filters = array();
    /**
     * Pausing type or defined pause time in seconds.
     * One pausing type is implemented: 'division' for dynamic calculation of pauses
     *
     * Defaults to 'division'
     *
     * @var    string|integer
     */
    private $pause = 'division';
    
    /**
     * The divisor of the division: batch-processing time / divisor.
     * This is used together with --pause=division in order to pause dynamically
     * in relation to the processing time
     * Defaults to 5
     *
     * @var    integer
     */
    private $divisor = 5;
    
    /**
     * Minimum processing time in seconds, in order to apply a pause
     * Defaults to 1
     *
     * @var    integer
     */
    private $minimumBatchProcessingTime = 1;
    /**
     * Constructor.
     *
     * @param   DispatcherInterface  $dispatcher  The dispatcher
     * @param   array                $config      An optional associative array of configuration settings
     *
     * @since   4.2.0
     */
    public function __construct(DispatcherInterface $dispatcher, array $config)
    {
        parent::__construct($dispatcher, $config);

    }
    /**
     * @inheritDoc
     *
     * @return string[]
     *
     * @since 4.1.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }
    
    private function cgindexer(ExecuteTaskEvent $event): int {
        $app = Factory::getApplication();
        $this->myparams = $event->getArgument('params');
        if ($this->myparams->purge == 1) {
			$this->getFilters();
			if (!$this->purge()) return TaskStatus::NOK;
            $this->index();
			// Restore the filters again.
			$this->putFilters();
        } else {
            $this->index();
		}
		
        return TaskStatus::OK;
    }
    private function purge(): bool {
        $model = new IndexModel();
        // Attempt to purge the index.
        $return = $model->purge();
        
        // If unsuccessful then abort.
        if (!$return)
        {
            $message = Text::_('PLG_TASK_CGINDEXER_PURGE_FAILED', $model->getError());
            $this->out($message);
            return false;
        }
        return true;
    }
    private function index(): bool {
        Indexer::resetState();
        PluginHelper::importPlugin('system');
        PluginHelper::importPlugin('finder');
        $this->pause = $this->myparams->pause;
        // Trigger the onStartIndex event.
        Factory::getApplication()->triggerEvent('onStartIndex');
        @set_time_limit(0);
        // Get the indexer state.
        $state = Indexer::getState();
        
        // Get the number of batches.
        $t = (int) $state->totalItems;
        $c = (int) ceil($t / $state->batchSize);
        $c = $c === 0 ? 1 : $c;
        
        try
        {
            // Process the batches.
            for ($i = 0; $i < $c; $i++)
            {
                $this->qtime = microtime(true);
                $state->batchOffset = 0;
                Factory::getApplication()->triggerEvent('onBuildIndex');
                $processingTime = round(microtime(true) - $this->qtime, 3);
                if ($this->pause !== 0)
                {
                    // Pausing Section
                    $skip  = !($processingTime >= $this->minimumBatchProcessingTime);
                    $pause = 0;
                    
                    if ($this->pause === 'division' && $this->divisor > 0)
                    {
                        if (!$skip)
                        {
                            $pause = round($processingTime / $this->divisor);
                        }
                        else
                        {
                            $pause = 1;
                        }
                    }
                    elseif ($this->pause > 0)
                    {
                        $pause = $this->pause;
                    }
                    
                    if ($pause > 0 && !$skip)
                    {
                        sleep($pause);
                    }
                    // End of Pausing Section
                }
            }
        } catch (\Exception $e)
        {
            // Reset the indexer state.
            Indexer::resetState();
            return false;
        }
        Indexer::resetState();
        return true;
    }
    /**
     * Save static filters.
     *
     * Since a purge/index cycle will cause all the taxonomy ids to change,
     * the static filters need to be updated with the new taxonomy ids.
     * The static filter information is saved prior to the purge/index
     * so that it can later be used to update the filters with new ids.
     *
     * @return  void
     *
     * @since   3.3
     */
    private function getFilters()
    {
        
        // Get the taxonomy ids used by the filters.
        $db    = Factory::getDbo();
        $query = $db->getQuery(true);
        $query
        ->select('filter_id, title, data')
        ->from($db->qn('#__finder_filters'));
        $filters = $db->setQuery($query)->loadObjectList();
        
        // Get the name of each taxonomy and the name of its parent.
        foreach ($filters as $filter)
        {
            // Skip empty filters.
            if ($filter->data === '')
            {
                continue;
            }
            
            // Get taxonomy records.
            $query = $db->getQuery(true);
            $query
            ->select('t.title, p.title AS parent')
            ->from($db->qn('#__finder_taxonomy') . ' AS t')
            ->leftJoin($db->qn('#__finder_taxonomy') . ' AS p ON p.id = t.parent_id')
            ->where($db->qn('t.id') . ' IN (' . $filter->data . ')');
            $taxonomies = $db->setQuery($query)->loadObjectList();
            
            // Construct a temporary data structure to hold the filter information.
            foreach ($taxonomies as $taxonomy)
            {
                $this->filters[$filter->filter_id][] = array(
                    'filter' => $filter->title,
                    'title'  => $taxonomy->title,
                    'parent' => $taxonomy->parent,
                );
            }
        }
        
    }
    /**
     * Restore static filters.
     *
     * Using the saved filter information, update the filter records
     * with the new taxonomy ids.
     *
     * @return  void
     *
     * @since   3.3
     */
    private function putFilters()
    {
        $db = Factory::getDbo();
        
        // Use the temporary filter information to update the filter taxonomy ids.
        foreach ($this->filters as $filter_id => $filter)
        {
            $tids = array();
            
            foreach ($filter as $element)
            {
                // Look for the old taxonomy in the new taxonomy table.
                $query = $db->getQuery(true);
                $query
                ->select('t.id')
                ->from($db->qn('#__finder_taxonomy') . ' AS t')
                ->leftJoin($db->qn('#__finder_taxonomy') . ' AS p ON p.id = t.parent_id')
                ->where($db->qn('t.title') . ' = ' . $db->q($element['title']))
                ->where($db->qn('p.title') . ' = ' . $db->q($element['parent']));
                $taxonomy = $db->setQuery($query)->loadResult();
                
                // If we found it then add it to the list.
                if ($taxonomy)
                {
                    $tids[] = $taxonomy;
                }
            }
            
            // Construct a comma-separated string from the taxonomy ids.
            $taxonomyIds = empty($tids) ? '' : implode(',', $tids);
            
            // Update the filter with the new taxonomy ids.
            $query = $db->getQuery(true);
            $query
            ->update($db->qn('#__finder_filters'))
            ->set($db->qn('data') . ' = ' . $db->q($taxonomyIds))
            ->where($db->qn('filter_id') . ' = ' . (int) $filter_id);
            $db->setQuery($query)->execute();
        }
    }
    
}