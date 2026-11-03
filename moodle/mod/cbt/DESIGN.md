# 🎨 API Contract & Internal Engine Design: `mod_cbt` (PHP)

## 1. Kontrak JSON Snapshot Soal (Tanpa Kunci Jawaban)
```json
{
  "examId": 42,
  "title": "Asesmen Sumatif Akhir Semester - Matematika Kelas 9",
  "durationSeconds": 7200,
  "maxStrikes": 3,
  "serverTimestamp": 1730682000,
  "attempt": {
    "attemptId": 1089,
    "timeStart": 1730681500,
    "extraTimeSeconds": 0,
    "remainingSeconds": 6700,
    "strikeCount": 1,
    "status": "inprogress",
    "syncVersion": 14
  },
  "questions": [
    {
      "slot": 1,
      "questionId": 5012,
      "type": "multichoice_single",
      "stemHtml": "<p>Jika persamaan kuadrat $$2x^2 - 7x + 3 = 0$$ memiliki akar-akar $$x_1$$ dan $$x_2$$, maka nilai dari $$x_1^2 + x_2^2$$ adalah...</p>",
      "options": [
        { "key": "A", "html": "$$\\frac{37}{4}$$" },
        { "key": "B", "html": "$$\\frac{49}{4}$$" },
        { "key": "C", "html": "$$\\frac{25}{4}$$" },
        { "key": "D", "html": "$$\\frac{31}{4}$$" }
      ]
    }
  ]
}
```
