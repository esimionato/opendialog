<?php


namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Facades\Serializer;
use App\Http\Requests\ConversationRequest;
use App\Http\Requests\ScenarioRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\ScenarioResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenDialogAi\Core\Console\Commands\CreateCoreConfigurations;
use OpenDialogAi\Core\Conversation\Behavior;
use OpenDialogAi\Core\Conversation\BehaviorsCollection;
use OpenDialogAi\Core\Conversation\Condition;
use OpenDialogAi\Core\Conversation\ConditionCollection;
use OpenDialogAi\Core\Conversation\Conversation;
use OpenDialogAi\Core\Conversation\Facades\ConversationDataClient;
use OpenDialogAi\Core\Conversation\Intent;
use OpenDialogAi\Core\Conversation\MessageTemplate;
use OpenDialogAi\Core\Conversation\Scenario;
use OpenDialogAi\Core\Conversation\Scene;
use OpenDialogAi\Core\Conversation\Turn;
use OpenDialogAi\MessageBuilder\MessageMarkUpGenerator;

class ScenariosController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Returns a collection of scenarios.
     *
     * @return ScenarioResource
     */
    public function index(): ScenarioResource
    {
        $scenarios = ConversationDataClient::getAllScenarios();
        return new ScenarioResource($scenarios);
    }

    /**
     * Display the specified scenario.
     *
     * @param Scenario $scenario
     * @return ScenarioResource
     */
    public function show(Scenario $scenario): ScenarioResource
    {
        return new ScenarioResource($scenario);
    }


    /**
     * Returns a collection of conversations for a particular scenario.
     *
     * @param Scenario $scenario
     * @return ConversationResource
     */
    public function showConversationsByScenario(Scenario $scenario): ConversationResource
    {
        $conversations = ConversationDataClient::getAllConversationsByScenario($scenario);
        return new ConversationResource($conversations);
    }

    /**
     * Store a newly created conversation against a particular scenario.
     *
     * @param Scenario $scenario
     * @param ConversationRequest $request
     * @return ConversationResource
     */
    public function storeConversationsAgainstScenario(Scenario $scenario, ConversationRequest $request): ConversationResource
    {
        $newConversation = Serializer::deserialize($request->getContent(), Conversation::class, 'json');
        $newConversation->setScenario($scenario);
        $conversation = ConversationDataClient::addConversation($newConversation);

        return new ConversationResource($conversation);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param ScenarioRequest $request
     * @return JsonResponse
     */
    public function store(ScenarioRequest $request): JsonResponse
    {
        /** @var Scenario $newScenario */
        $newScenario = Serializer::deserialize($request->getContent(), Scenario::class, 'json');

        if ($newScenario->getInterpreter() === "") {
            $newScenario->setInterpreter(CreateCoreConfigurations::DEFAULT_CALLBACK);
        }

        $persistedScenario = $this->createDefaultConversations($newScenario);

        // Add a new condition to the scenario now that it has an ID
        $condition = new Condition(
            'eq',
            ['attribute' => 'user.selected_scenario'],
            ['value' => $persistedScenario->getUid()]
        );

        $persistedScenario->setConditions(new ConditionCollection([$condition]));

        $updatedScenario = ConversationDataClient::updateScenario($persistedScenario);

        return (new ScenarioResource($updatedScenario))->response()->setStatusCode(201);
    }

    /**
     * @param Scenario $scenario
     * @return Scenario
     */
    private function createDefaultConversations(Scenario $scenario): Scenario
    {
        $scenarioName = $scenario->getName();
        $scenarioNameAsId = preg_replace('/\s/', '', ucwords($scenario->getOdId()));
        $welcomeOutgoingIntentId = "intent.app.welcomeResponseFor$scenarioNameAsId";
        $noMatchOutgoingIntentId = "intent.app.noMatchResponse$scenarioNameAsId";

        $welcomeConversation = $this->createAtomicCallbackConversation(
            $scenario,
            'Welcome',
            'intent.core.welcome',
            'Hello from user',
            $welcomeOutgoingIntentId,
            "Hi! This is the default welcome message for the $scenarioName Scenario."
        );

        $noMatchConversation = $this->createAtomicCallbackConversation(
            $scenario,
            'No Match',
            'intent.core.NoMatch',
            '[no match]',
            $noMatchOutgoingIntentId,
            'Sorry, I didn\'t understand that'
        );

        $scenario->addConversation($welcomeConversation);
        $scenario->addConversation($noMatchConversation);

        return ConversationDataClient::addFullScenarioGraph($scenario);
    }

    /**
     * Update the specified scenario.
     *
     * @param ScenarioRequest $request
     * @param Scenario $scenario
     * @return ScenarioResource
     */
    public function update(ScenarioRequest $request, Scenario $scenario): ScenarioResource
    {
        $scenarioUpdate = Serializer::deserialize($request->getContent(), Scenario::class, 'json');
        $updatedScenario = ConversationDataClient::updateScenario($scenarioUpdate);
        return new ScenarioResource($updatedScenario);
    }

    /**
     * Destroy the specified scenario.
     *
     * @param Scenario $scenario
     * @return Response $response
     */
    public function destroy(Scenario $scenario): Response
    {
        if (ConversationDataClient::deleteScenarioByUid($scenario->getUid())) {
            return response()->noContent(200);
        } else {
            return response('Error deleting scenario, check the logs', 500);
        }
    }

    /**
     * @param Scenario $scenario
     * @param string $name
     * @param string $incomingIntentId
     * @param string $incomingSampleUtterance
     * @param string $outgoingIntentId
     * @param string $outgoingSampleUtterance
     * @return Conversation
     */
    private function createAtomicCallbackConversation(
        Scenario $scenario,
        string $name,
        string $incomingIntentId,
        string $incomingSampleUtterance,
        string $outgoingIntentId,
        string $outgoingSampleUtterance
    ): Conversation {
        $nameAsId = preg_replace('/\s/', '_', strtolower($name));

        $conversation = new Conversation($scenario);
        $conversation->setName("$name Conversation");
        $conversation->setOdId(sprintf('%s_conversation', $nameAsId));
        $conversation->setDescription('Automatically generated');
        $conversation->setInterpreter('');
        $conversation->setBehaviors(new BehaviorsCollection([new Behavior(Behavior::STARTING_BEHAVIOR)]));
        $conversation->setCreatedAt(Carbon::now());
        $conversation->setUpdatedAt(Carbon::now());

        $scene = new Scene($conversation);
        $scene->setName("$name Scene");
        $scene->setOdId(sprintf('%s_scene', $nameAsId));
        $scene->setDescription('Automatically generated');
        $scene->setInterpreter('');
        $scene->setBehaviors(new BehaviorsCollection([new Behavior(Behavior::STARTING_BEHAVIOR)]));
        $scene->setCreatedAt(Carbon::now());
        $scene->setUpdatedAt(Carbon::now());

        $turn = new Turn($scene);
        $turn->setName("$name Turn");
        $turn->setOdId(sprintf('%s_turn', $nameAsId));
        $turn->setDescription('Automatically generated');
        $turn->setInterpreter('');
        $turn->setBehaviors(new BehaviorsCollection([new Behavior(Behavior::STARTING_BEHAVIOR)]));
        $turn->setCreatedAt(Carbon::now());
        $turn->setUpdatedAt(Carbon::now());

        $requestIntent = new Intent($turn, Intent::USER);
        $requestIntent->setIsRequestIntent(true);
        $requestIntent->setName($incomingIntentId);
        $requestIntent->setOdId($incomingIntentId);
        $requestIntent->setDescription('Automatically generated');
        $requestIntent->setSampleUtterance($incomingSampleUtterance);
        $requestIntent->setInterpreter(CreateCoreConfigurations::DEFAULT_CALLBACK);
        $requestIntent->setConfidence(1);
        $requestIntent->setCreatedAt(Carbon::now());
        $requestIntent->setUpdatedAt(Carbon::now());

        $responseIntent = new Intent($turn, Intent::APP);
        $responseIntent->setIsRequestIntent(false);
        $responseIntent->setName($outgoingIntentId);
        $responseIntent->setOdId($outgoingIntentId);
        $responseIntent->setDescription('Automatically generated');
        $responseIntent->setSampleUtterance($outgoingSampleUtterance);
        $responseIntent->setConfidence(1);
        $responseIntent->setBehaviors(new BehaviorsCollection([new Behavior(Behavior::COMPLETING_BEHAVIOR)]));
        $responseIntent->setCreatedAt(Carbon::now());
        $responseIntent->setUpdatedAt(Carbon::now());

        $messageTemplate = new MessageTemplate();
        $messageTemplate->setName('auto generated');
        $messageTemplate->setOdId('auto_generated');
        $messageTemplate->setMessageMarkup((new MessageMarkUpGenerator())->addTextMessage($outgoingSampleUtterance)->getMarkUp());

        $responseIntent->addMessageTemplate($messageTemplate);

        $turn->addRequestIntent($requestIntent);
        $turn->addResponseIntent($responseIntent);
        $scene->addTurn($turn);
        $conversation->addScene($scene);

        return $conversation;
    }
}
