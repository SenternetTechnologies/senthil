(() => {
  "use strict";
  const form = document.getElementById("early-access-form");
  const message = document.getElementById("form-message");
  const submit = form?.querySelector("button[type=submit]");
  const label = form?.querySelector(".submit-label");
  const year = document.getElementById("year");
  if (year) year.textContent = String(new Date().getFullYear());

  // Set this to your deployed PHP endpoint, e.g. https://api.example.com/api/index.php.
  // GitHub Pages cannot execute PHP itself.
  const API_ENDPOINT = "";

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();
    message.className = "form-message";
    if (!API_ENDPOINT) {
      message.textContent = "The site design is live-ready, but submissions are not connected yet. Deploy the PHP API and set API_ENDPOINT in assets/app.js.";
      message.classList.add("error");
      return;
    }
    if (!form.reportValidity()) return;
    const data = new FormData(form);
    const payload = {
      name: String(data.get("name") || "").trim(),
      email: String(data.get("email") || "").trim(),
      consent: data.get("consent") === "on"
    };
    submit.disabled = true;
    if (label) label.textContent = "Submitting…";
    try {
      const response = await fetch(API_ENDPOINT, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(payload),
        mode: "cors",
        credentials: "omit"
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.error || "Submission failed. Please try again.");
      form.reset();
      message.textContent = "Thanks — your early-access request has been received.";
      message.classList.add("success");
    } catch (error) {
      message.textContent = error instanceof Error ? error.message : "Unable to submit right now. Please try again later.";
      message.classList.add("error");
    } finally {
      submit.disabled = false;
      if (label) label.textContent = "Request early access";
    }
  });
})();