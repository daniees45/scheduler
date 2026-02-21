"""
Genetic Algorithm for Schedule Generation
Fallback solver when CSP backtracking fails
Uses evolutionary techniques to find feasible/near-feasible schedules
"""

import random
import numpy as np
from copy import deepcopy
from typing import List, Dict, Tuple, Any, Callable, Optional
import time
from data_model import ClassSection


class ScheduleIndividual:
    """
    Represents a single schedule (candidate solution) in the population
    Chromosome: List of (section_id, day, slot, room) tuples
    """
    
    def __init__(self, sections: List[ClassSection], domains: Dict[str, List]):
        """
        Initialize random individual
        
        Args:
            sections: List of ClassSection objects to schedule
            domains: Domain dict mapping section_id to possible (day, slot, room) tuples
        """
        self.sections = sections
        self.domains = domains
        self.chromosome = {}  # section_id -> (day, slot, room)
        self.fitness = None
        self.violations = []
        
        # Random initialization: assign each section a random value from its domain
        for section in sections:
            if section.id in domains and domains[section.id]:
                self.chromosome[section.id] = random.choice(domains[section.id])
            else:
                # Fallback: create a random assignment
                self.chromosome[section.id] = (
                    random.randint(0, 4),  # day 0-4
                    random.randint(0, 3),  # slot 0-3
                    f"Room_{random.randint(1, 10)}"
                )
    
    @staticmethod
    def from_chromosome(chromosome: Dict, sections: List[ClassSection], domains: Dict):
        """Create individual from existing chromosome"""
        individual = ScheduleIndividual(sections, domains)
        individual.chromosome = deepcopy(chromosome)
        return individual
    
    def mutate(self, mutation_rate: float = 0.1):
        """
        Mutate: randomly change some assignments
        
        Args:
            mutation_rate: Probability of mutating each gene (0-1)
        """
        for section_id in self.chromosome:
            if random.random() < mutation_rate:
                section = next((s for s in self.sections if s.id == section_id), None)
                if section and section_id in self.domains and self.domains[section_id]:
                    self.chromosome[section_id] = random.choice(self.domains[section_id])
        
        self.fitness = None  # Invalidate fitness after mutation
    
    def crossover(self, other: 'ScheduleIndividual') -> 'ScheduleIndividual':
        """
        Crossover with another individual (single-point crossover)
        
        Args:
            other: Another ScheduleIndividual
        
        Returns:
            New offspring individual
        """
        offspring = ScheduleIndividual(self.sections, self.domains)
        
        # Single-point crossover
        section_ids = list(self.chromosome.keys())
        crossover_point = random.randint(1, len(section_ids) - 1)
        
        for i, section_id in enumerate(section_ids):
            if i < crossover_point:
                offspring.chromosome[section_id] = deepcopy(self.chromosome[section_id])
            else:
                offspring.chromosome[section_id] = deepcopy(other.chromosome[section_id])
        
        return offspring
    
    def get_conflicts(self, constraints: List[Callable], lecturers: Dict) -> List[Tuple]:
        """
        Check constraints and return list of violations
        
        Args:
            constraints: List of constraint functions
            lecturers: Dict of Lecturer objects
        
        Returns:
            List of (section_id, constraint_name, severity) violations
        """
        violations = []
        
        for section in self.sections:
            if section.id not in self.chromosome:
                continue
            
            assignment = self.chromosome.copy()
            day, slot, room = assignment.get(section.id, (None, None, None))
            
            # Check each constraint
            for constraint in constraints:
                try:
                    if not constraint({section.id: (day, slot, room)}, section.id, (day, slot, room)):
                        violations.append((section.id, constraint.__name__, 1))
                except:
                    pass
        
        self.violations = violations
        return violations
    
    def count_violations(self) -> int:
        """Return total number of constraint violations"""
        return len(self.violations)


class GeneticAlgorithm:
    """
    Genetic Algorithm solver for schedule generation
    Used as fallback when CSP backtracking times out or fails
    """
    
    def __init__(self, 
                 sections: List[ClassSection],
                 domains: Dict[str, List],
                 constraints: List[Callable],
                 lecturers: Dict,
                 population_size: int = 50,
                 generations: int = 100,
                 mutation_rate: float = 0.15,
                 crossover_rate: float = 0.8,
                 elitism_rate: float = 0.1,
                 timeout_seconds: int = 30):
        """
        Initialize GA solver
        
        Args:
            sections: List of ClassSection objects
            domains: Domain dict for each section
            constraints: List of constraint functions
            lecturers: Dict of Lecturer objects
            population_size: Size of population
            generations: Max generations to run
            mutation_rate: Probability of mutation per gene
            crossover_rate: Probability of crossover
            elitism_rate: Fraction of best individuals to keep
            timeout_seconds: Max time to run
        """
        self.sections = sections
        self.domains = domains
        self.constraints = constraints
        self.lecturers = lecturers
        
        self.population_size = population_size
        self.max_generations = generations
        self.mutation_rate = mutation_rate
        self.crossover_rate = crossover_rate
        self.elitism_rate = elitism_rate
        self.timeout_seconds = timeout_seconds
        
        self.population = []
        self.best_fitness_history = []
        self.avg_fitness_history = []
        self.start_time = None
        self.elapsed_time = 0.0
        self.generation_count = 0
        
        print("[GA] Genetic Algorithm initialized")
        print(f"  Population: {population_size}")
        print(f"  Generations: {generations}")
        print(f"  Mutation Rate: {mutation_rate:.1%}")
    
    def _fitness_function(self, individual: ScheduleIndividual) -> float:
        """
        Calculate fitness score for an individual
        Higher = fewer violations
        
        Fitness = 1.0 - (violations / total_possible_violations)
        """
        violations = individual.get_conflicts(self.constraints, self.lecturers)
        violation_count = len(violations)
        
        # Max possible violations: 5 constraints per section
        max_violations = len(self.sections) * 5
        
        if max_violations == 0:
            fitness = 1.0
        else:
            fitness = 1.0 - (violation_count / max_violations)
        
        individual.fitness = fitness
        return fitness
    
    def initialize_population(self):
        """Create initial population of random individuals"""
        self.population = []
        for _ in range(self.population_size):
            individual = ScheduleIndividual(self.sections, self.domains)
            self._fitness_function(individual)
            self.population.append(individual)
        
        print(f"[GA] Initial population created: {self.population_size} individuals")
    
    def selection(self, tournament_size: int = 3) -> ScheduleIndividual:
        """
        Tournament selection: randomly pick tournament_size individuals,
        return the one with best fitness
        """
        tournament = random.sample(self.population, min(tournament_size, len(self.population)))
        return max(tournament, key=lambda x: x.fitness)
    
    def evolve_generation(self):
        """Perform one generation of evolution"""
        # Elitism: keep top individuals
        elite_count = max(1, int(self.population_size * self.elitism_rate))
        sorted_pop = sorted(self.population, key=lambda x: x.fitness, reverse=True)
        new_population = sorted_pop[:elite_count]
        
        # Generate offspring through crossover and mutation
        while len(new_population) < self.population_size:
            if random.random() < self.crossover_rate:
                # Crossover
                parent1 = self.selection()
                parent2 = self.selection()
                offspring = parent1.crossover(parent2)
            else:
                # Mutation only
                offspring = deepcopy(self.selection())
            
            # Apply mutation
            offspring.mutate(self.mutation_rate)
            self._fitness_function(offspring)
            new_population.append(offspring)
        
        self.population = new_population[:self.population_size]
        
        # Track statistics
        fitnesses = [ind.fitness for ind in self.population]
        best_fitness = max(fitnesses)
        avg_fitness = np.mean(fitnesses)
        
        self.best_fitness_history.append(best_fitness)
        self.avg_fitness_history.append(avg_fitness)
        
        return best_fitness
    
    def has_timeout(self) -> bool:
        """Check if timeout exceeded"""
        self.elapsed_time = time.time() - self.start_time
        return self.elapsed_time > self.timeout_seconds
    
    def solve(self) -> Optional[Dict]:
        """
        Run GA solver
        
        Returns:
            Dict mapping section_id -> (day, slot, room), or None if no feasible solution found
        """
        self.start_time = time.time()
        self.initialize_population()
        
        best_overall = max(self.population, key=lambda x: x.fitness)
        
        print(f"\n[GA] Starting evolution ({self.max_generations} generations, timeout {self.timeout_seconds}s)...")
        
        for generation in range(self.max_generations):
            if self.has_timeout():
                print(f"[GA] ⏱️  Timeout reached after {generation} generations ({self.elapsed_time:.2f}s)")
                break
            
            best_gen_fitness = self.evolve_generation()
            
            # Track best overall
            current_best = max(self.population, key=lambda x: x.fitness)
            if current_best.fitness > best_overall.fitness:
                best_overall = deepcopy(current_best)
            
            self.generation_count = generation + 1
            
            # Progress reporting
            if (generation + 1) % 10 == 0 or generation == 0:
                violation_count = best_overall.count_violations()
                print(f"[GA] Gen {generation+1:3d}: Best Fitness={best_gen_fitness:.3f}, "
                      f"Best Overall={best_overall.fitness:.3f}, Violations={violation_count}")
            
            # Early termination if perfect solution found
            if best_overall.fitness >= 0.99:
                print(f"[GA] ✓ Near-perfect solution found at generation {generation+1}")
                break
        
        self.elapsed_time = time.time() - self.start_time
        
        # Report final statistics
        print(f"\n[GA] ===== EVOLUTION COMPLETE =====")
        print(f"  Generations: {self.generation_count}")
        print(f"  Time: {self.elapsed_time:.2f}s")
        print(f"  Best Fitness: {best_overall.fitness:.3f}")
        print(f"  Violations: {best_overall.count_violations()}")
        print(f"  Improvement: {self.best_fitness_history[-1] - self.best_fitness_history[0]:.3f}")
        
        return best_overall.chromosome
    
    def get_statistics(self) -> Dict:
        """Return GA statistics"""
        if not self.best_fitness_history:
            return {}
        
        return {
            'generations': self.generation_count,
            'elapsed_time': self.elapsed_time,
            'best_fitness': self.best_fitness_history[-1] if self.best_fitness_history else 0,
            'initial_fitness': self.best_fitness_history[0] if self.best_fitness_history else 0,
            'improvement': self.best_fitness_history[-1] - self.best_fitness_history[0] if self.best_fitness_history else 0,
            'avg_final_fitness': np.mean(self.avg_fitness_history[-10:]) if len(self.avg_fitness_history) >= 10 else 0,
            'population_size': self.population_size,
            'timeout_seconds': self.timeout_seconds
        }
    
    def plot_evolution(self, output_file: str = 'ga_evolution.png'):
        """
        Plot fitness evolution over generations
        
        Args:
            output_file: Path to save plot
        """
        try:
            import matplotlib.pyplot as plt
            
            generations = list(range(len(self.best_fitness_history)))
            
            plt.figure(figsize=(10, 6))
            plt.plot(generations, self.best_fitness_history, 'b-', label='Best Fitness', linewidth=2)
            plt.plot(generations, self.avg_fitness_history, 'r--', label='Average Fitness', linewidth=1.5)
            plt.xlabel('Generation')
            plt.ylabel('Fitness Score')
            plt.title('Genetic Algorithm Evolution')
            plt.legend()
            plt.grid(True, alpha=0.3)
            plt.savefig(output_file, dpi=150)
            print(f"[GA] Evolution plot saved to {output_file}")
            return True
        except ImportError:
            print("[GA] Matplotlib not available. Skipping plot.")
            return False


def solve_with_ga(sections: List[ClassSection],
                  domains: Dict[str, List],
                  constraints: List[Callable],
                  lecturers: Dict,
                  population_size: int = 50,
                  generations: int = 100,
                  timeout_seconds: int = 30) -> Optional[Dict]:
    """
    Convenience function to run GA solver
    
    Args:
        sections, domains, constraints, lecturers: Problem definition (same as CSP)
        population_size: Population size (smaller = faster, larger = better quality)
        generations: Max generations
        timeout_seconds: Max time to run
    
    Returns:
        Solution dict or None
    """
    ga = GeneticAlgorithm(
        sections, domains, constraints, lecturers,
        population_size=population_size,
        generations=generations,
        timeout_seconds=timeout_seconds
    )
    
    return ga.solve()


# Demo/Testing
if __name__ == "__main__":
    print("[GA] Genetic Algorithm Module - Ready for use as fallback scheduler")
    print("\nUsage:")
    print("  from genetic_algorithm import solve_with_ga")
    print("  solution = solve_with_ga(sections, domains, constraints, lecturers)")
    print("\nPerfect for:")
    print("  - When CSP times out")
    print("  - Large/complex scheduling problems")
    print("  - Quick near-optimal solutions")
