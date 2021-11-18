<?php
/*
 * Copyright (c) Atsuhiro Kubo <kubo@iteman.jp> and contributors,
 * All rights reserved.
 *
 * This file is part of Workflower.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\Workflower\Process;

use PHPMentors\Workflower\Workflow\Activity\ActivityInterface;
use PHPMentors\Workflower\Workflow\Activity\UnexpectedActivityStateException;
use PHPMentors\Workflower\Workflow\Event\StartEvent;
use PHPMentors\Workflower\Workflow\Operation\OperationRunnerInterface;
use PHPMentors\Workflower\Workflow\ProcessInstance;
use PHPMentors\Workflower\Workflow\Provider\DataProviderInterface;
use PHPMentors\Workflower\Workflow\WorkflowRepositoryInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class Process
{
    /**
     * @var int|string|WorkflowContextInterface
     */
    private $workflowContext;

    /**
     * @var WorkflowRepositoryInterface
     */
    private $workflowRepository;

    /**
     * @var ExpressionLanguage
     *
     * @since Property available since Release 1.2.0
     */
    private $expressionLanguage;

    /**
     * @var OperationRunnerInterface
     *
     * @since Property available since Release 1.2.0
     */
    private $operationRunner;

    /**
     * @var DataProviderInterface
     *
     * @since Property available since Release 1.2.0
     */
    private $dataProvider;

    /**
     * @param int|string|WorkflowContextInterface $workflowContext
     * @param WorkflowRepositoryInterface $workflowRepository
     * @param OperationRunnerInterface $operationRunner
     */
    public function __construct($workflowContext, WorkflowRepositoryInterface $workflowRepository, OperationRunnerInterface $operationRunner)
    {
        $this->workflowContext = $workflowContext;
        $this->workflowRepository = $workflowRepository;
        $this->operationRunner = $operationRunner;
    }

    /**
     * @param ExpressionLanguage $expressionLanguage
     *
     * @since Method available since Release 1.2.0
     */
    public function setExpressionLanguage(ExpressionLanguage $expressionLanguage)
    {
        $this->expressionLanguage = $expressionLanguage;
    }

    /**
     * @param DataProviderInterface $dataProvider
     *
     * @since Method available since Release 1.2.0
     */
    public function setDataProvider(DataProviderInterface $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    /**
     * @param EventContextInterface $eventContext
     * @param ProcessInstance $processInstance
     */
    public function start(EventContextInterface $eventContext, ProcessInstance $processInstance = null)
    {
        assert($eventContext->getProcessContext() !== null);
        assert($eventContext->getProcessContext()->getProcessInstance() === null);
        assert($eventContext->getEventId() !== null);

        if ($processInstance) {
            $processInstance = $this->configureWorkflow($processInstance);
        } else {
            $processInstance = $this->configureWorkflow($this->createWorkflow());
        }

        $eventContext->getProcessContext()->setProcessInstance($processInstance);
        $processInstance->setProcessData($eventContext->getProcessContext()->getProcessData());
        $flowObject = $processInstance->getFlowObject($eventContext->getEventId());
        $processInstance->start(/* @var $flowObject StartEvent */ $flowObject);
    }

    /**
     * @param WorkItemContextInterface $workItemContext
     */
    public function allocateWorkItem(WorkItemContextInterface $workItemContext)
    {
        assert($workItemContext->getProcessContext() !== null);
        assert($workItemContext->getProcessContext()->getProcessInstance() !== null);
        assert($workItemContext->getActivityId() !== null);

        $processInstance = $this->configureWorkflow($workItemContext->getProcessContext()->getProcessInstance());
        /* @var $flowObject ActivityInterface */
        $flowObject = $processInstance->getFlowObject($workItemContext->getActivityId());
        $workItem = current($flowObject->getWorkItems()->getActiveInstances());
        $processInstance->allocateWorkItem($workItem, $workItemContext->getParticipant());
    }

    /**
     * @param WorkItemContextInterface $workItemContext
     */
    public function startWorkItem(WorkItemContextInterface $workItemContext)
    {
        assert($workItemContext->getProcessContext() !== null);
        assert($workItemContext->getProcessContext()->getProcessInstance() !== null);
        assert($workItemContext->getActivityId() !== null);

        $processInstance = $this->configureWorkflow($workItemContext->getProcessContext()->getProcessInstance());
        /* @var $flowObject ActivityInterface */
        $flowObject = $processInstance->getFlowObject($workItemContext->getActivityId());
        $workItem = current($flowObject->getWorkItems()->getActiveInstances());
        $processInstance->startWorkItem($workItem, $workItemContext->getParticipant());
    }

    /**
     * @param WorkItemContextInterface $workItemContext
     */
    public function completeWorkItem(WorkItemContextInterface $workItemContext)
    {
        assert($workItemContext->getProcessContext() !== null);
        assert($workItemContext->getProcessContext()->getProcessInstance() !== null);
        assert($workItemContext->getActivityId() !== null);

        $processInstance = $this->configureWorkflow($workItemContext->getProcessContext()->getProcessInstance());
        $processInstance->setProcessData($workItemContext->getProcessContext()->getProcessData());
        /* @var $flowObject ActivityInterface */
        $flowObject = $processInstance->getFlowObject($workItemContext->getActivityId());
        $workItem = current($flowObject->getWorkItems()->getActiveInstances());
        $processInstance->completeWorkItem($workItem, $workItemContext->getParticipant());
    }

    /**
     * @param WorkItemContextInterface $workItemContext
     *
     * @throws UnexpectedActivityStateException
     */
    public function executeWorkItem(WorkItemContextInterface $workItemContext)
    {
        assert($workItemContext->getProcessContext() !== null);
        assert($workItemContext->getProcessContext()->getProcessInstance() !== null);
        assert($workItemContext->getActivityId() !== null);
        assert($workItemContext->getProcessContext()->getProcessInstance()->getFlowObject($workItemContext->getActivityId()) instanceof ActivityInterface);

        $activity = $workItemContext->getProcessContext()->getProcessInstance()->getFlowObject($workItemContext->getActivityId());
        /* @var $activity ActivityInterface */
        if ($activity->isAllocatable()) {
            $this->allocateWorkItem($workItemContext);
            $nextWorkItemContext = new WorkItemContext($workItemContext->getParticipant());
            $nextWorkItemContext->setActivityId($workItemContext->getProcessContext()->getProcessInstance()->getCurrentFlowObject()->getId());
            $nextWorkItemContext->setProcessContext($workItemContext->getProcessContext());

            return $this->executeWorkItem($nextWorkItemContext);
        } elseif ($activity->isStartable()) {
            $this->startWorkItem($workItemContext);
            $nextWorkItemContext = new WorkItemContext($workItemContext->getParticipant());
            $nextWorkItemContext->setActivityId($workItemContext->getProcessContext()->getProcessInstance()->getCurrentFlowObject()->getId());
            $nextWorkItemContext->setProcessContext($workItemContext->getProcessContext());

            return $this->executeWorkItem($nextWorkItemContext);
        } elseif ($activity->isCompletable()) {
            $this->completeWorkItem($workItemContext);
        } else {
            throw new UnexpectedActivityStateException(sprintf('The current work item of the activity "%s" is not executable.', $activity->getId()));
        }
    }

    /**
     * @return int|string|WorkflowContextInterface
     *
     * @since Method available since Release 1.1.0
     */
    public function getWorkflowContext()
    {
        return $this->workflowContext;
    }

    /**
     * @return ProcessInstance
     *
     * @throws WorkflowNotFoundException
     */
    private function createWorkflow()
    {
        $workflowId = $this->workflowContext instanceof WorkflowContextInterface ? $this->workflowContext->getWorkflowId() : $this->workflowContext;
        $processInstance = $this->workflowRepository->findById($workflowId);
        if ($processInstance === null) {
            throw new WorkflowNotFoundException(sprintf('The processInstance "%s" is not found.', $workflowId));
        }

        return $processInstance;
    }

    /**
     * @param ProcessInstance $processInstance
     *
     * @return ProcessInstance
     *
     * @since Method available since Release 1.2.0
     */
    private function configureWorkflow(ProcessInstance $processInstance)
    {
        if ($this->expressionLanguage !== null) {
            $processInstance->setExpressionLanguage($this->expressionLanguage);
        }

        if ($this->operationRunner !== null) {
            $processInstance->setOperationRunner($this->operationRunner);
        }

        if( $this->dataProvider !== null){
            $processInstance->setDataProvider($this->dataProvider);
        }

        return $processInstance;
    }
}
