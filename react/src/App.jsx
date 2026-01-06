export default function App() {
  return (
    <div className="app">
      <header className="app__header">
        <p className="app__eyebrow">AgentWP</p>
        <h1>Command Deck Dev UI</h1>
        <p className="app__lead">
          Hot reload is wired through the Node container. Edit this file to
          verify live updates.
        </p>
      </header>
      <section className="app__card">
        <h2>Local endpoints</h2>
        <ul>
          <li>WordPress: http://localhost:8080</li>
          <li>Mailhog: http://localhost:8025</li>
          <li>React: http://localhost:5173</li>
        </ul>
      </section>
    </div>
  );
}
