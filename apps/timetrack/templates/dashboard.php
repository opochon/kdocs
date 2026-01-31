<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>K-Time - Timesheet</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen" x-data="timeApp()">
    <!-- Header -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <a href="/kdocs" class="text-gray-500 hover:text-gray-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
                <h1 class="text-2xl font-bold text-gray-900">K-Time</h1>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-gray-600"><?= htmlspecialchars($user['username']) ?></span>
                <span class="text-sm text-gray-500"><?= date('l j F Y') ?></span>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-6">
        <!-- Timer Widget -->
        <div class="bg-white rounded-lg shadow p-6 mb-6" x-show="timer.active || showTimerForm" x-cloak>
            <!-- Timer actif -->
            <div x-show="timer.active" class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="text-4xl font-mono font-bold text-blue-600" x-text="timer.formatted"></div>
                    <div>
                        <div class="font-medium" x-text="timer.project_name || timer.client_name || 'Sans projet'"></div>
                        <div class="text-sm text-gray-500" x-text="timer.description || ''"></div>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button @click="pauseTimer()" x-show="!timer.is_paused"
                            class="px-4 py-2 bg-yellow-500 text-white rounded hover:bg-yellow-600">
                        Pause
                    </button>
                    <button @click="resumeTimer()" x-show="timer.is_paused"
                            class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600">
                        Reprendre
                    </button>
                    <button @click="stopTimer()"
                            class="px-4 py-2 bg-red-500 text-white rounded hover:bg-red-600">
                        Terminer
                    </button>
                </div>
            </div>

            <!-- Formulaire nouveau timer -->
            <div x-show="!timer.active && showTimerForm">
                <h3 class="font-medium mb-3">Demarrer un timer</h3>
                <div class="grid grid-cols-3 gap-4">
                    <select x-model="newTimer.project_id" class="border rounded px-3 py-2">
                        <option value="">-- Projet --</option>
                        <?php foreach ($projects as $p): ?>
                        <option value="<?= $p->id ?>"><?= htmlspecialchars($p->client_name . ' - ' . $p->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" x-model="newTimer.description" placeholder="Description..."
                           class="border rounded px-3 py-2">
                    <div class="flex space-x-2">
                        <button @click="startTimer()" class="flex-1 px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                            Demarrer
                        </button>
                        <button @click="showTimerForm = false" class="px-4 py-2 bg-gray-300 rounded hover:bg-gray-400">
                            Annuler
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Entry -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex space-x-4">
                <div class="flex-1">
                    <input type="text" x-model="quickInput"
                           @keyup.enter="submitQuickEntry()"
                           @input="previewQuickEntry()"
                           placeholder="Saisie rapide: 2.5hA1 pAA2 description..."
                           class="w-full border rounded-lg px-4 py-3 text-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <div x-show="quickPreview" class="mt-2 text-sm text-gray-600" x-text="quickPreview"></div>
                    <div x-show="quickErrors.length" class="mt-2 text-sm text-red-600">
                        <template x-for="err in quickErrors"><span x-text="err + ' '"></span></template>
                    </div>
                </div>
                <button @click="submitQuickEntry()"
                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium">
                    Ajouter
                </button>
                <button @click="showTimerForm = true" x-show="!timer.active"
                        class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Aujourd'hui</div>
                <div class="text-2xl font-bold"><?= number_format($todayStats['total_hours'], 1) ?>h</div>
                <div class="text-sm text-gray-600"><?= number_format($todayStats['total_amount'], 2) ?> CHF</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Cette semaine</div>
                <div class="text-2xl font-bold"><?= number_format($weekStats['total_hours'], 1) ?>h</div>
                <div class="text-sm text-gray-600"><?= number_format($weekStats['total_amount'], 2) ?> CHF</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Entrees</div>
                <div class="text-2xl font-bold"><?= $weekStats['count'] ?></div>
                <div class="text-sm text-gray-600"><?= $weekStats['billed_count'] ?> facturees</div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-sm text-gray-500">Objectif</div>
                <div class="text-2xl font-bold"><?= number_format(($weekStats['total_hours'] / 40) * 100, 0) ?>%</div>
                <div class="text-sm text-gray-600">40h/semaine</div>
            </div>
        </div>

        <!-- Entrees du jour -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h2 class="text-lg font-semibold">Entrees - <?= date('l j F', strtotime($today)) ?></h2>
            </div>
            <div class="divide-y" x-data>
                <?php if (empty($todayEntries)): ?>
                <div class="px-6 py-8 text-center text-gray-500">
                    Aucune entree aujourd'hui. Utilisez la saisie rapide ci-dessus.
                </div>
                <?php else: ?>
                <?php foreach ($todayEntries as $entry): ?>
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 text-right">
                            <span class="text-lg font-mono font-medium">
                                <?= floor($entry->duration) ?>:<?= sprintf('%02d', ($entry->duration - floor($entry->duration)) * 60) ?>
                            </span>
                        </div>
                        <div>
                            <div class="font-medium">
                                <?php if ($entry->project_quick_code): ?>
                                <span class="text-xs bg-blue-100 text-blue-700 px-1 rounded"><?= htmlspecialchars($entry->project_quick_code) ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($entry->project_name ?? $entry->client_name ?? 'Sans projet') ?>
                            </div>
                            <?php if ($entry->description): ?>
                            <div class="text-sm text-gray-500"><?= htmlspecialchars($entry->description) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="text-right">
                            <div class="font-medium"><?= number_format($entry->amount, 2) ?> CHF</div>
                            <div class="text-xs text-gray-500"><?= $entry->rate ?>/h</div>
                        </div>
                        <button @click="deleteEntry(<?= $entry->id ?>)" class="text-red-500 hover:text-red-700">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
    function timeApp() {
        return {
            quickInput: '',
            quickPreview: '',
            quickErrors: [],
            showTimerForm: false,
            timer: {
                active: <?= $activeTimer ? 'true' : 'false' ?>,
                <?php if ($activeTimer): ?>
                id: <?= $activeTimer->id ?>,
                project_name: '<?= addslashes($activeTimer->project_name ?? '') ?>',
                client_name: '<?= addslashes($activeTimer->client_name ?? '') ?>',
                description: '<?= addslashes($activeTimer->description ?? '') ?>',
                is_paused: <?= $activeTimer->is_paused ? 'true' : 'false' ?>,
                elapsed_seconds: <?= $activeTimer->getElapsedSeconds() ?>,
                formatted: '<?= $activeTimer->getFormattedDuration() ?>',
                <?php endif; ?>
            },
            newTimer: {
                project_id: '',
                description: ''
            },

            init() {
                if (this.timer.active) {
                    this.startTimerUpdate();
                }
            },

            startTimerUpdate() {
                setInterval(() => {
                    if (this.timer.active && !this.timer.is_paused) {
                        this.timer.elapsed_seconds++;
                        const h = Math.floor(this.timer.elapsed_seconds / 3600);
                        const m = Math.floor((this.timer.elapsed_seconds % 3600) / 60);
                        const s = this.timer.elapsed_seconds % 60;
                        this.timer.formatted = `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
                    }
                }, 1000);
            },

            async previewQuickEntry() {
                if (this.quickInput.length < 2) {
                    this.quickPreview = '';
                    this.quickErrors = [];
                    return;
                }
                try {
                    const res = await fetch('/kdocs/time/entries/parse?input=' + encodeURIComponent(this.quickInput));
                    const data = await res.json();
                    this.quickPreview = data.preview || '';
                    this.quickErrors = data.errors || [];
                } catch (e) {
                    console.error(e);
                }
            },

            async submitQuickEntry() {
                if (!this.quickInput.trim()) return;

                try {
                    const res = await fetch('/kdocs/time/entries/quick', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({input: this.quickInput, date: '<?= $today ?>'})
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.quickInput = '';
                        this.quickPreview = '';
                        location.reload();
                    } else {
                        this.quickErrors = data.errors || [data.error];
                    }
                } catch (e) {
                    console.error(e);
                }
            },

            async startTimer() {
                try {
                    const res = await fetch('/kdocs/time/timer/start', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(this.newTimer)
                    });
                    const data = await res.json();
                    if (data.success) {
                        this.timer = {...data.timer, active: true};
                        this.showTimerForm = false;
                        this.startTimerUpdate();
                    }
                } catch (e) {
                    console.error(e);
                }
            },

            async pauseTimer() {
                const res = await fetch('/kdocs/time/timer/pause', {method: 'POST'});
                const data = await res.json();
                if (data.success) {
                    this.timer.is_paused = true;
                }
            },

            async resumeTimer() {
                const res = await fetch('/kdocs/time/timer/resume', {method: 'POST'});
                const data = await res.json();
                if (data.success) {
                    this.timer.is_paused = false;
                }
            },

            async stopTimer() {
                const res = await fetch('/kdocs/time/timer/stop', {method: 'POST'});
                const data = await res.json();
                if (data.success) {
                    this.timer.active = false;
                    location.reload();
                }
            },

            async deleteEntry(id) {
                if (!confirm('Supprimer cette entree ?')) return;
                const res = await fetch('/kdocs/time/entries/' + id, {method: 'DELETE'});
                if (res.ok) location.reload();
            }
        }
    }
    </script>
</body>
</html>
