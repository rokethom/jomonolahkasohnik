<style>
    .ai-flow-readonly {
        display: grid;
        gap: 12px;
        padding: 14px;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 16px;
        background: rgba(15, 23, 42, .04);
    }

    .ai-flow-readonly h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 800;
    }

    .ai-flow-steps {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 8px;
    }

    .ai-flow-step {
        min-height: 88px;
        padding: 10px;
        border: 1px solid rgba(59, 130, 246, .2);
        border-radius: 12px;
        background: rgba(239, 246, 255, .86);
        color: #0f172a;
    }

    .dark .ai-flow-step {
        background: rgba(30, 41, 59, .74);
        color: #e2e8f0;
    }

    .ai-flow-step b {
        display: block;
        margin-bottom: 6px;
        color: #0369a1;
        font-size: 12px;
    }

    .ai-flow-step span {
        font-size: 12px;
        line-height: 1.35;
    }

    @media (max-width: 1100px) {
        .ai-flow-steps {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="ai-flow-readonly">
    <h3>Flow AI Parser Read Only</h3>
    <div class="ai-flow-steps">
        <div class="ai-flow-step">
            <b>1. Parser Lokal</b>
            <span>Keyword, form schema, template, dan parser DB dicoba lebih dulu agar hemat API.</span>
        </div>
        <div class="ai-flow-step">
            <b>2. Memory AI</b>
            <span>Jika input pernah sukses diparse AI, sistem pakai hasil tersimpan tanpa panggil provider.</span>
        </div>
        <div class="ai-flow-step">
            <b>3. AI Fallback</b>
            <span>OpenRouter/OpenAI/Kimi dipanggil hanya saat parser lokal belum cukup yakin.</span>
        </div>
        <div class="ai-flow-step">
            <b>4. Validasi Laravel</b>
            <span>AI hanya ekstrak JSON. Harga, area, GPS, limit, dan create order tetap dari service backend.</span>
        </div>
        <div class="ai-flow-step">
            <b>5. Simpan Rule</b>
            <span>Hasil AI valid disimpan sebagai AI Parser Memory agar sistem makin mandiri.</span>
        </div>
    </div>
</div>
